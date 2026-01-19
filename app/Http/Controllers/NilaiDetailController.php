<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\NilaiDetail;
use App\Models\StrukturNilaiMapel;
use App\Models\Nilai;
use App\Models\TahunAjaran;
use App\Models\CatatanMapelSiswa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NilaiDetailController extends Controller
{
    public function index($kelas_id, $struktur_id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

        $nilaiDetails = NilaiDetail::with(['siswa', 'inputByGuru'])
            ->where('struktur_nilai_mapel_id', $struktur_id)
            ->get();

        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->whereNull('deleted_at')
            ->get(['id', 'nama']);

        $grouped = [];
        foreach ($siswaList as $siswa) {
            $nilaiSiswa = $nilaiDetails->where('siswa_id', $siswa->id);

            $nilaiData = [];

            foreach ($nilaiSiswa as $n) {
                if ($n->lm_key === null || $n->lm_key === "") {
                    $nilaiData[$n->kolom_key] = $n->nilai;
                } else {
                    if (!isset($nilaiData[$n->lm_key])) {
                        $nilaiData[$n->lm_key] = [];
                    }
                    $nilaiData[$n->lm_key][$n->kolom_key] = $n->nilai;
                }
            }

            $catatan = CatatanMapelSiswa::where('siswa_id', $siswa->id)
                ->where('struktur_nilai_mapel_id', $struktur_id)
                ->first();

            $grouped[] = [
                'siswa_id' => $siswa->id,
                'siswa_nama' => $siswa->nama,
                'nilai_data' => $nilaiData,
                'catatan' => $catatan ? $catatan->catatan : null,
            ];
        }

        return response()->json([
            'struktur' => $struktur,
            'data' => $grouped,
        ]);
    }

    public function storeSingle(Request $request, $kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $validated = $request->validate([
            'siswa_id' => 'required|integer|exists:siswa,id',
            'lm_key' => 'nullable|string',
            'kolom_key' => 'required|string',
            'nilai' => 'required|numeric|between:0,100',
        ]);

        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

        $siswa = DB::table('siswa')->where('id', $validated['siswa_id'])->where('kelas_id', $kelas_id)->first();
        if (!$siswa) {
            return response()->json(['message' => 'Siswa tidak ditemukan di kelas ini'], 404);
        }

        try {
            DB::beginTransaction();

            $nilaiDetail = NilaiDetail::updateOrCreate(
                [
                    'siswa_id' => $validated['siswa_id'],
                    'struktur_nilai_mapel_id' => $struktur_id,
                    'lm_key' => $validated['lm_key'],
                    'kolom_key' => $validated['kolom_key'],
                ],
                [
                    'mapel_id' => $struktur->mapel_id,
                    'semester_id' => $struktur->semester_id,
                    'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                    'nilai' => $validated['nilai'],
                    'input_by_guru_id' => $guruId,
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Nilai berhasil disimpan',
                'data' => $nilaiDetail->load('siswa', 'inputByGuru')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Store single nilai detail error: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function storeBulk(Request $request, $kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.siswa_id' => 'required|integer|exists:siswa,id',
            'data.*.nilai_data' => 'required|array',
            'data.*.catatan' => 'nullable|string', // ✅ 1 catatan per siswa per mapel
        ]);

        $saved = [];
        $skipped = [];

        try {
            DB::beginTransaction();

            foreach ($validated['data'] as $item) {
                $siswa = DB::table('siswa')->where('id', $item['siswa_id'])->first();
                if (!$siswa || $siswa->kelas_id != $kelas_id) {
                    $skipped[] = [
                        'siswa_id' => $item['siswa_id'],
                        'reason' => 'Siswa tidak ditemukan di kelas ini'
                    ];
                    continue;
                }

                $nilaiData = $item['nilai_data'];
                $catatan = $item['catatan'] ?? null; // ✅ Catatan per mapel

                if (empty($nilaiData)) {
                    $skipped[] = [
                        'siswa_id' => $item['siswa_id'],
                        'reason' => 'Tidak ada nilai yang dikirim untuk siswa ini'
                    ];
                    continue;
                }

                $nilaiCount = 0;

                // Process nested structure (nilai detail)
                foreach ($nilaiData as $key => $value) {
                    if (is_array($value)) {
                        // Nested LM
                        $lmKey = $key;
                        foreach ($value as $kolomKey => $nilaiValue) {
                            if ($nilaiValue === null || $nilaiValue === '') {
                                continue;
                            }

                            NilaiDetail::updateOrCreate(
                                [
                                    'siswa_id' => $item['siswa_id'],
                                    'struktur_nilai_mapel_id' => $struktur_id,
                                    'lm_key' => $lmKey,
                                    'kolom_key' => $kolomKey,
                                ],
                                [
                                    'mapel_id' => $struktur->mapel_id,
                                    'semester_id' => $struktur->semester_id,
                                    'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                                    'nilai' => $nilaiValue,
                                    'input_by_guru_id' => $guruId,
                                ]
                            );
                            $nilaiCount++;
                        }
                    } else {
                        // Flat (ASLIM/ASAS)
                        if ($value === null || $value === '') {
                            continue;
                        }

                        NilaiDetail::updateOrCreate(
                            [
                                'siswa_id' => $item['siswa_id'],
                                'struktur_nilai_mapel_id' => $struktur_id,
                                'lm_key' => null,
                                'kolom_key' => $key,
                            ],
                            [
                                'mapel_id' => $struktur->mapel_id,
                                'semester_id' => $struktur->semester_id,
                                'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                                'nilai' => $value,
                                'input_by_guru_id' => $guruId,
                            ]
                        );
                        $nilaiCount++;
                    }
                }

                // ✅ SIMPAN CATATAN PER MAPEL (terpisah dari nilai detail)
                if ($catatan) {
                    CatatanMapelSiswa::updateOrCreate(
                        [
                            'siswa_id' => $item['siswa_id'],
                            'struktur_nilai_mapel_id' => $struktur_id,
                        ],
                        [
                            'catatan' => $catatan,
                            'input_by_guru_id' => $guruId,
                        ]
                    );
                }

                if ($nilaiCount > 0) {
                    $saved[] = [
                        'siswa_id' => $item['siswa_id'],
                        'siswa_nama' => $siswa->nama,
                        'nilai_saved' => $nilaiCount,
                        'catatan_saved' => $catatan ? true : false,
                    ];
                } else {
                    $skipped[] = [
                        'siswa_id' => $item['siswa_id'],
                        'reason' => 'Tidak ada nilai valid yang dikirim'
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Nilai berhasil disimpan',
                'summary' => [
                    'total_siswa' => count($validated['data']),
                    'saved' => count($saved),
                    'skipped' => count($skipped),
                ],
                'saved' => $saved,
                'skipped' => $skipped
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Store bulk nilai detail error: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getProgress($kelas_id, $struktur_id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->with(['mapel', 'semester'])
            ->findOrFail($struktur_id);

        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->whereNull('deleted_at')
            ->orderBy('nama')
            ->get();

        $lingkup = $struktur->struktur['lingkup_materi'] ?? [];

        $totalKolom = 0;
        foreach ($lingkup as $lm) {
            $formatif = $lm['formatif'] ?? [];
            $totalKolom += count($formatif);
        }
        $totalKolom += 2; // +ASLIM +ASAS

        $progress = [];

        foreach ($siswaList as $siswa) {
            $nilaiCount = NilaiDetail::where('siswa_id', $siswa->id)
                ->where('struktur_nilai_mapel_id', $struktur_id)
                ->count();

            $percentage = $totalKolom > 0 ? round(($nilaiCount / $totalKolom) * 100) : 0;

            $progress[] = [
                'siswa_id' => $siswa->id,
                'siswa_nama' => $siswa->nama,
                'nilai_terisi' => $nilaiCount,
                'total_kolom' => $totalKolom,
                'percentage' => $percentage,
                'status' => $percentage == 100 ? 'complete' : ($percentage > 0 ? 'partial' : 'empty')
            ];
        }

        $complete = collect($progress)->where('status', 'complete')->count();
        $partial = collect($progress)->where('status', 'partial')->count();
        $empty = collect($progress)->where('status', 'empty')->count();

        return response()->json([
            'kelas' => [
                'id' => $kelas_id,
                'nama' => DB::table('kelas')->where('id', $kelas_id)->value('nama')
            ],
            'struktur' => [
                'id' => $struktur->id,
                'mapel' => $struktur->mapel->nama,
                'semester' => $struktur->semester->nama,
                'total_kolom' => $totalKolom
            ],
            'summary' => [
                'total_siswa' => $siswaList->count(),
                'complete' => $complete,
                'partial' => $partial,
                'empty' => $empty,
                'completion_rate' => $siswaList->count() > 0
                    ? round(($complete / $siswaList->count()) * 100)
                    : 0
            ],
            'progress' => $progress
        ]);
    }


public function generateNilaiAkhir($kelas_id, $struktur_id)
{
    $user = Auth::guard('api')->user();
    $guruId = $user->guru ? $user->guru->id : null;

    $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
        ->with(['mapel', 'semester', 'tahunAjaran'])
        ->findOrFail($struktur_id);

    $kelas = \App\Models\Kelas::with('mapels')->findOrFail($kelas_id);
    $mapelExists = $kelas->mapels()->where('mapel_id', $struktur->mapel_id)->exists();

    if (!$mapelExists) {
        return response()->json([
            'message' => "Mapel '{$struktur->mapel->nama}' sudah tidak di-assign ke kelas {$kelas->nama}."
        ], 422);
    }

    $siswaList = DB::table('siswa')
        ->where('kelas_id', $kelas_id)
        ->whereNull('deleted_at')
        ->get(['id', 'nama']);

    $summary = [
        'success' => 0,
        'skipped_incomplete' => 0,
        'failed' => 0,
        'details' => [],
    ];

    try {
        DB::beginTransaction();

        foreach ($siswaList as $siswa) {
            $result = $this->calculateNilaiAkhir($siswa->id, $struktur);

            if (!$result['success']) {
                $summary['skipped_incomplete']++;
                $summary['details'][] = [
                    'siswa_id' => $siswa->id,
                    'siswa_nama' => $siswa->nama,
                    'status' => 'skipped',
                    'reason' => $result['message'],
                    'missing' => $result['missing'] ?? null,
                ];
                continue;
            }

            // ✅ PERBAIKAN: AMBIL CATATAN PER MAPEL (1 catatan untuk seluruh mapel)
            $catatanMapel = CatatanMapelSiswa::where('siswa_id', $siswa->id)
                ->where('struktur_nilai_mapel_id', $struktur_id)
                ->first();

            // ✅ PERBAIKAN: Catatan dari catatan_mapel_siswa, bukan auto-generated
            $catatanFinal = $catatanMapel && $catatanMapel->catatan
                ? $catatanMapel->catatan
                : null; // ✅ Null jika tidak ada catatan, bukan auto-generated message

            try {
                Nilai::updateOrInsert(
                    [
                        'siswa_id' => $siswa->id,
                        'mapel_id' => $struktur->mapel_id,
                        'semester_id' => $struktur->semester_id,
                        'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                    ],
                    [
                        'nilai' => $result['nilai_akhir'],
                        'catatan' => $catatanFinal, // ✅ CATATAN DARI CATATAN_MAPEL_SISWA atau NULL
                        'catatan_source' => 'generated', // ✅ Tandai bahwa ini di-generate
                        'input_by_guru_id' => $guruId,
                        'updated_at' => now(),
                    ]
                );

                $summary['success']++;
                $summary['details'][] = [
                    'siswa_id' => $siswa->id,
                    'siswa_nama' => $siswa->nama,
                    'status' => 'success',
                    'nilai_akhir' => $result['nilai_akhir'],
                    'catatan' => $catatanFinal ?? '(tidak ada catatan)',
                    'catatan_source' => 'generated',
                ];
            } catch (\Exception $e) {
                $summary['failed']++;
                $summary['details'][] = [
                    'siswa_id' => $siswa->id,
                    'siswa_nama' => $siswa->nama,
                    'status' => 'failed',
                    'reason' => 'Database error: ' . $e->getMessage(),
                ];
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'Generate nilai akhir selesai',
            'kelas' => ['id' => $kelas->id, 'nama' => $kelas->nama],
            'mapel' => ['id' => $struktur->mapel->id, 'nama' => $struktur->mapel->nama],
            'semester' => ['id' => $struktur->semester->id, 'nama' => $struktur->semester->nama],
            'summary' => $summary,
            'note' => $summary['skipped_incomplete'] > 0
                ? "{$summary['skipped_incomplete']} siswa di-skip karena data nilai belum lengkap"
                : null,
            'catatan_note' => 'Catatan akademik diambil dari catatan_mapel_siswa. Siswa tanpa catatan akan memiliki nilai NULL.'
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }
}

    protected function calculateNilaiAkhir($siswa_id, $struktur)
    {
        $nilaiDetails = NilaiDetail::where('siswa_id', $siswa_id)
            ->where('struktur_nilai_mapel_id', $struktur->id)
            ->get();

        if ($nilaiDetails->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Belum ada nilai detail yang diinput untuk siswa ini',
            ];
        }

        $strukturData = $struktur->struktur;

        $allFormatifValues = [];
        $formatifPerLM = [];
        $missingData = [];

        foreach ($strukturData['lingkup_materi'] as $lm) {
            $lmKey = $lm['lm_key'];
            $lmLabel = $lm['lm_label'];
            $formatifValues = [];
            $lmMissing = [];

            foreach ($lm['formatif'] as $formatif) {
                $kolomKey = $formatif['kolom_key'];

                $nilaiDetail = $nilaiDetails->where('lm_key', $lmKey)
                    ->where('kolom_key', $kolomKey)
                    ->first();

                if (!$nilaiDetail || $nilaiDetail->nilai === null) {
                    $lmMissing[] = $formatif['kolom_label'];
                    continue;
                }

                $formatifValues[] = $nilaiDetail->nilai;
                $allFormatifValues[] = $nilaiDetail->nilai;
            }

            if (!empty($formatifValues)) {
                $formatifPerLM[$lmLabel] = array_sum($formatifValues) / count($formatifValues);
            }

            if (!empty($lmMissing)) {
                $missingData[$lmLabel] = $lmMissing;
            }
        }

        $aslimKey = $strukturData['aslim']['kolom_key'];
        $aslimNilai = $nilaiDetails->where('kolom_key', $aslimKey)->first();

        if (!$aslimNilai || $aslimNilai->nilai === null) {
            $missingData['ASLIM'] = ['Nilai UTS belum diinput'];
        }

        $asasKey = $strukturData['asas']['kolom_key'];
        $asasNilai = $nilaiDetails->where('kolom_key', $asasKey)->first();

        if (!$asasNilai || $asasNilai->nilai === null) {
            $missingData['ASAS'] = ['Nilai UAS belum diinput'];
        }

        if (!empty($missingData)) {
            return [
                'success' => false,
                'message' => 'Data nilai tidak lengkap',
                'missing' => $missingData,
            ];
        }

        if (empty($allFormatifValues)) {
            return [
                'success' => false,
                'message' => 'Tidak ada nilai formatif yang diinput',
            ];
        }

        $rataRata = array_sum($allFormatifValues) / count($allFormatifValues);
        $aslim = $aslimNilai->nilai;
        $asas = $asasNilai->nilai;

        $nilaiAkhir = ($rataRata + $aslim + $asas) / 3;

        return [
            'success' => true,
            'nilai_akhir' => round($nilaiAkhir, 2),
            'detail' => [
                'formatif_per_lm' => array_map(fn($v) => round($v, 2), $formatifPerLM),
                'rata_rata_formatif' => round($rataRata, 2),
                'total_formatif_count' => count($allFormatifValues),
                'aslim' => round($aslim, 2),
                'asas' => round($asas, 2),
                'formula' => sprintf(
                    "RATA-RATA(%.2f) + ASLIM(%.2f) + ASAS(%.2f) / 3 = %.2f",
                    $rataRata,
                    $aslim,
                    $asas,
                    $nilaiAkhir
                ),
            ],
        ];
    }

    public function getSiswaDetail($kelas_id, $struktur_id, $siswa_id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)
            ->with(['mapel', 'semester', 'tahunAjaran'])
            ->findOrFail($struktur_id);

        $siswa = DB::table('siswa')
            ->where('id', $siswa_id)
            ->where('kelas_id', $kelas_id)
            ->first();

        if (!$siswa) {
            return response()->json(['message' => 'Siswa tidak ditemukan di kelas ini'], 404);
        }

        $nilaiDetails = NilaiDetail::with(['inputByGuru'])
            ->where('siswa_id', $siswa_id)
            ->where('struktur_nilai_mapel_id', $struktur_id)
            ->get();

        $nilaiData = [];
        foreach ($nilaiDetails as $n) {
            if (!isset($nilaiData[$n->lm_key])) {
                $nilaiData[$n->lm_key] = [];
            }
            $nilaiData[$n->lm_key][$n->kolom_key] = [
                'nilai' => $n->nilai,
                'input_by' => $n->inputByGuru ? $n->inputByGuru->nama : null,
                'updated_at' => $n->updated_at,
            ];
        }

        // ✅ GET CATATAN PER MAPEL
        $catatanMapel = CatatanMapelSiswa::with('inputByGuru')
            ->where('siswa_id', $siswa_id)
            ->where('struktur_nilai_mapel_id', $struktur_id)
            ->first();

        $result = $this->calculateNilaiAkhir($siswa_id, $struktur);

        $nilaiAkhir = Nilai::where('siswa_id', $siswa_id)
            ->where('mapel_id', $struktur->mapel_id)
            ->where('semester_id', $struktur->semester_id)
            ->where('tahun_ajaran_id', $struktur->tahun_ajaran_id)
            ->first();

        return response()->json([
            'siswa' => $siswa,
            'struktur' => $struktur,
            'nilai_data' => $nilaiData,
            'catatan' => $catatanMapel ? [
                'catatan' => $catatanMapel->catatan,
                'input_by' => $catatanMapel->inputByGuru ? $catatanMapel->inputByGuru->nama : null,
                'updated_at' => $catatanMapel->updated_at,
            ] : null,
            'perhitungan' => $result,
            'nilai_akhir_tersimpan' => $nilaiAkhir ? [
                'nilai' => $nilaiAkhir->nilai,
                'catatan' => $nilaiAkhir->catatan,
                'updated_at' => $nilaiAkhir->updated_at,
            ] : null,
        ]);
    }
}
