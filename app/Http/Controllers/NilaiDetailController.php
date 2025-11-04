<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\NilaiDetail;
use App\Models\StrukturNilaiMapel;
use App\Models\Nilai;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
                if (!isset($nilaiData[$n->lm_key])) {
                    $nilaiData[$n->lm_key] = [];
                }
                $nilaiData[$n->lm_key][$n->kolom_key] = $n->nilai;
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

    public function storeBulk(Request $request, $kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.siswa_id' => 'required|integer|exists:siswa,id',
            'data.*.nilai_data' => 'required|array',
        ]);

        try {
            DB::beginTransaction();

            foreach ($validated['data'] as $item) {
                $siswa = DB::table('siswa')->where('id', $item['siswa_id'])->first();
                if (!$siswa || $siswa->kelas_id != $kelas_id) {
                    continue;
                }

                foreach ($item['nilai_data'] as $lmKey => $kolomData) {
                    foreach ($kolomData as $kolomKey => $nilai) {
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
                                'nilai' => $nilai,
                                'input_by_guru_id' => $guruId,
                            ]
                        );
                    }
                }
            }

            DB::commit();

            return response()->json(['message' => 'Nilai berhasil disimpan']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function generateNilaiAkhir($kelas_id, $struktur_id)
    {
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

        $siswaList = DB::table('siswa')->where('kelas_id', $kelas_id)->get(['id']);

        $summary = [
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        try {
            DB::beginTransaction();

            foreach ($siswaList as $siswa) {
                $result = $this->calculateNilaiAkhir($siswa->id, $struktur);

                if ($result['success']) {
                    Nilai::updateOrCreate(
                        [
                            'siswa_id' => $siswa->id,
                            'mapel_id' => $struktur->mapel_id,
                            'semester_id' => $struktur->semester_id,
                            'tahun_ajaran_id' => $struktur->tahun_ajaran_id,
                        ],
                        [
                            'nilai' => $result['nilai_akhir'],
                            'catatan' => 'Auto-generated dari nilai detail',
                            'is_generated' => true,
                            'sumber_perhitungan' => json_encode($result['detail']),
                            'input_by_guru_id' => $guruId,
                            'updated_at' => now(),
                        ]
                    );
                    $summary['success']++;
                } else {
                    $summary['failed']++;
                    $summary['details'][] = [
                        'siswa_id' => $siswa->id,
                        'reason' => $result['message'],
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Generate nilai akhir selesai',
                'summary' => $summary,
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

        $strukturData = $struktur->struktur;
        $rataRataPerLM = [];
        $aslimList = [];
        $asasList = [];

        foreach ($strukturData as $lm) {
            $lmKey = $lm['lm_key'];
            $formatifValues = [];
            $aslimValue = null;
            $asasValue = null;

            foreach ($lm['kolom'] as $kolom) {
                $kolomKey = $kolom['kolom_key'];
                $tipe = $kolom['tipe'];

                $nilaiDetail = $nilaiDetails->where('lm_key', $lmKey)
                    ->where('kolom_key', $kolomKey)
                    ->first();

                if (!$nilaiDetail || $nilaiDetail->nilai === null) {
                    continue;
                }

                if ($tipe === 'formatif') {
                    $formatifValues[] = $nilaiDetail->nilai;
                } elseif ($tipe === 'aslim') {
                    $aslimValue = $nilaiDetail->nilai;
                } elseif ($tipe === 'asas') {
                    $asasValue = $nilaiDetail->nilai;
                }
            }

            if (!empty($formatifValues)) {
                $rataRataPerLM[$lmKey] = array_sum($formatifValues) / count($formatifValues);
            }

            if ($aslimValue !== null) {
                $aslimList[$lmKey] = $aslimValue;
            }

            if ($asasValue !== null) {
                $asasList[$lmKey] = $asasValue;
            }
        }

        if (empty($rataRataPerLM) || empty($aslimList) || empty($asasList)) {
            return [
                'success' => false,
                'message' => 'Data nilai tidak lengkap',
            ];
        }

        $avgRataRata = array_sum($rataRataPerLM) / count($rataRataPerLM);
        $avgAslim = array_sum($aslimList) / count($aslimList);
        $avgAsas = array_sum($asasList) / count($asasList);

        $nilaiAkhir = ($avgRataRata + $avgAslim + $avgAsas) / 3;

        return [
            'success' => true,
            'nilai_akhir' => round($nilaiAkhir, 2),
            'detail' => [
                'rata_rata_per_lm' => $rataRataPerLM,
                'avg_rata_rata' => round($avgRataRata, 2),
                'aslim_per_lm' => $aslimList,
                'avg_aslim' => round($avgAslim, 2),
                'asas_per_lm' => $asasList,
                'avg_asas' => round($avgAsas, 2),
                'formula' => "({$avgRataRata} + {$avgAslim} + {$avgAsas}) / 3",
            ],
        ];
    }

    public function getSiswaDetail($kelas_id, $struktur_id, $siswa_id)
    {
        $struktur = StrukturNilaiMapel::where('kelas_id', $kelas_id)->findOrFail($struktur_id);

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

        return response()->json([
            'siswa' => $siswa,
            'struktur' => $struktur,
            'nilai_data' => $nilaiData,
            'perhitungan' => $result,
        ]);
    }
}
