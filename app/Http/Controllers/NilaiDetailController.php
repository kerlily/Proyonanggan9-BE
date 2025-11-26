<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\NilaiDetail;
use App\Models\StrukturNilaiMapel;
use App\Models\Nilai;
use App\Models\TahunAjaran;
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

    $siswaList = DB::table('siswa')->where('kelas_id', $kelas_id)->get(['id', 'nama']);

    $grouped = [];
    foreach ($siswaList as $siswa) {
        $nilaiSiswa = $nilaiDetails->where('siswa_id', $siswa->id);

        $nilaiData = [];
        foreach ($nilaiSiswa as $n) {
            // ✅ FIXED: Format untuk frontend React
            if ($n->lm_key === null || $n->lm_key === "") {
                // ASLIM/ASAS: simpan di root level
                $nilaiData[$n->kolom_key] = $n->nilai;
            } else {
                // LM: simpan di nested object
                if (!isset($nilaiData[$n->lm_key])) {
                    $nilaiData[$n->lm_key] = [];
                }
                $nilaiData[$n->lm_key][$n->kolom_key] = $n->nilai;
            }
        }

        $grouped[] = [
            'siswa_id' => $siswa->id,
            'siswa_nama' => $siswa->nama,
            'nilai_data' => $nilaiData,
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
        'lm_key' => 'nullable|string', // ✅ nullable untuk ASLIM/ASAS
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
                'lm_key' => $validated['lm_key'], // ✅ Bisa null
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
        'data.*.nilai_data' => 'required|array', // ✅ PASTIKAN INI ADA
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

            // ✅ DEBUG LOG
            \Log::info("Nilai data received for siswa {$item['siswa_id']}:", $nilaiData);

            if (empty($nilaiData)) {
                $skipped[] = [
                    'siswa_id' => $item['siswa_id'],
                    'reason' => 'Tidak ada nilai yang dikirim untuk siswa ini'
                ];
                continue;
            }

            $nilaiCount = 0;

            // ✅ PROCESS NESTED STRUCTURE
            foreach ($nilaiData as $key => $value) {
                if (is_array($value)) {
                    // Nested LM (lingkup_materi)
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

            if ($nilaiCount > 0) {
                $saved[] = [
                    'siswa_id' => $item['siswa_id'],
                    'siswa_nama' => $siswa->nama,
                    'nilai_saved' => $nilaiCount
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

        $siswaList = DB::table('siswa')->where('kelas_id', $kelas_id)->orderBy('nama')->get();

        // Hitung total kolom yang harus diisi
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

        // Summary
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

    /**
     * ✅ FIXED: Generate nilai akhir dengan validasi lengkap dan sync dengan tabel nilai
     */
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

        $siswaList = DB::table('siswa')->where('kelas_id', $kelas_id)->get(['id', 'nama']);

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

                try {
                    Nilai::updateOrCreate(
                        [
                            'siswa_id' => $siswa->id,
                            'mapel_id' => $struktur->mapel_id,
                            'semester_id' => $struktur->semester_id,
                            'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                        ],
                        [
                            'nilai' => $result['nilai_akhir'],
                            'catatan' => 'Auto-generated dari nilai detail pada ' . now()->format('Y-m-d H:i:s'),
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
                    : null
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

        // ✅ Ambil semua nilai formatif dari semua LM
        $allFormatifValues = [];
        $formatifPerLM = [];
        $missingData = [];

        // Loop through Lingkup Materi
        foreach ($strukturData['lingkup_materi'] as $lm) {
            $lmKey = $lm['lm_key'];
            $lmLabel = $lm['lm_label'];
            $formatifValues = [];
            $lmMissing = [];

            // Ambil semua nilai formatif di LM ini
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

        // ✅ Ambil ASLIM (UTS) - hanya 1
        $aslimKey = $strukturData['aslim']['kolom_key'];
        $aslimNilai = $nilaiDetails->where('kolom_key', $aslimKey)->first();

        if (!$aslimNilai || $aslimNilai->nilai === null) {
            $missingData['ASLIM'] = ['Nilai UTS (Ujian Tengah Semester) belum diinput'];
        }

        // ✅ Ambil ASAS (UAS) - hanya 1
        $asasKey = $strukturData['asas']['kolom_key'];
        $asasNilai = $nilaiDetails->where('kolom_key', $asasKey)->first();

        if (!$asasNilai || $asasNilai->nilai === null) {
            $missingData['ASAS'] = ['Nilai UAS (Ujian Akhir Semester) belum diinput'];
        }

        // Validasi kelengkapan data
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

        // ✅ Hitung RATA-RATA dari semua formatif
        $rataRata = array_sum($allFormatifValues) / count($allFormatifValues);
        $aslim = $aslimNilai->nilai;
        $asas = $asasNilai->nilai;

        // ✅ NILAI AKHIR = (RATA-RATA + ASLIM + ASAS) / 3
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

        $result = $this->calculateNilaiAkhir($siswa_id, $struktur);

        // ✅ Cek apakah nilai akhir sudah ada di tabel nilai
        $nilaiAkhir = Nilai::where('siswa_id', $siswa_id)
            ->where('mapel_id', $struktur->mapel_id)
            ->where('semester_id', $struktur->semester_id)
            ->where('tahun_ajaran_id', $struktur->tahun_ajaran_id)
            ->first();

        return response()->json([
            'siswa' => $siswa,
            'struktur' => $struktur,
            'nilai_data' => $nilaiData,
            'perhitungan' => $result,
            'nilai_akhir_tersimpan' => $nilaiAkhir ? [
                'nilai' => $nilaiAkhir->nilai,
                'catatan' => $nilaiAkhir->catatan,
                'updated_at' => $nilaiAkhir->updated_at,
            ] : null,
        ]);
    }
}
