<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TahunAjaran;
use App\Models\Semester;
use App\Models\Kelas;

class NilaiMonitoringController extends Controller
{
    public function monitoring(Request $request)
    {
        // Get semester & tahun ajaran (default: yang aktif)
        $tahunAjaranId = $request->query('tahun_ajaran_id');
        $semesterId = $request->query('semester_id');

        if (!$tahunAjaranId) {
            $tahunAktif = TahunAjaran::where('is_active', true)->first();
            if (!$tahunAktif) {
                return response()->json([
                    'message' => 'Tidak ada tahun ajaran aktif'
                ], 404);
            }
            $tahunAjaranId = $tahunAktif->id;
        } else {
            $tahunAktif = TahunAjaran::find($tahunAjaranId);
        }

        if (!$semesterId) {
            $semesterAktif = Semester::where('tahun_ajaran_id', $tahunAjaranId)
                ->where('is_active', true)
                ->first();

            if (!$semesterAktif) {
                return response()->json([
                    'message' => 'Tidak ada semester aktif'
                ], 404);
            }
            $semesterId = $semesterAktif->id;
        } else {
            $semesterAktif = Semester::find($semesterId);
        }

        // Filters
        $kelasIdFilter = $request->query('kelas_id');
        $guruIdFilter = $request->query('guru_id');
        $completionBelow = $request->query('completion_below');

        // Get semua kelas
        $kelasQuery = Kelas::query();

        if ($kelasIdFilter) {
            $kelasQuery->where('id', $kelasIdFilter);
        }

        $kelasList = $kelasQuery->orderBy('tingkat')->orderBy('section')->get();

        $result = [];
        $overallStats = [
            'total_kelas' => 0,
            'kelas_complete' => 0,
            'kelas_partial' => 0,
            'kelas_empty' => 0,
            'total_siswa' => 0,
            'total_nilai_expected' => 0,
            'total_nilai_terisi' => 0,
            'overall_completion_rate' => 0,
        ];

        foreach ($kelasList as $kelas) {
            // Get wali kelas untuk tahun ajaran ini
            $waliKelas = DB::table('wali_kelas')
                ->join('guru', 'wali_kelas.guru_id', '=', 'guru.id')
                ->where('wali_kelas.kelas_id', $kelas->id)
                ->where('wali_kelas.tahun_ajaran_id', $tahunAjaranId)
                ->where('wali_kelas.is_primary', true)
                ->select('guru.id as guru_id', 'guru.nama as guru_nama')
                ->first();

            // Filter by guru jika ada
            if ($guruIdFilter && (!$waliKelas || $waliKelas->guru_id != $guruIdFilter)) {
                continue;
            }

            // ✅ Get total siswa AKTIF di kelas (exclude soft deleted)
            $totalSiswa = DB::table('riwayat_kelas')
                ->join('siswa', 'riwayat_kelas.siswa_id', '=', 'siswa.id')
                ->where('riwayat_kelas.kelas_id', $kelas->id)
                ->where('riwayat_kelas.tahun_ajaran_id', $tahunAjaranId)
                ->whereNull('siswa.deleted_at')
                ->count();

            // Jika tidak ada riwayat, pakai siswa yang aktif sekarang
            if ($totalSiswa == 0) {
                $totalSiswa = DB::table('siswa')
                    ->where('kelas_id', $kelas->id)
                    ->where('is_alumni', false)
                    ->whereNull('deleted_at')
                    ->count();
            }

            // Get total mapel yang di-assign ke kelas ini
            $totalMapel = DB::table('kelas_mapel')
                ->where('kelas_id', $kelas->id)
                ->count();

            // Expected total nilai = siswa x mapel
            $nilaiExpected = $totalSiswa * $totalMapel;

            // ✅ Get total nilai yang sudah terisi (HANYA untuk siswa yang AKTIF)
            $nilaiTerisi = DB::table('nilai')
                ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                ->where('siswa.kelas_id', $kelas->id)
                ->whereNull('siswa.deleted_at')
                ->where('nilai.semester_id', $semesterId)
                ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
                ->whereNotNull('nilai.nilai')
                ->count();

            // ✅ Hitung completion rate dengan cap di 100%
            $completionRate = $nilaiExpected > 0
                ? min(round(($nilaiTerisi / $nilaiExpected) * 100, 1), 100)
                : 0;

            // Filter by completion_below jika ada
            if ($completionBelow !== null && $completionRate >= $completionBelow) {
                continue;
            }

            // Status
            $status = 'empty';
            if ($completionRate == 100) {
                $status = 'complete';
                $overallStats['kelas_complete']++;
            } elseif ($completionRate > 0) {
                $status = 'partial';
                $overallStats['kelas_partial']++;
            } else {
                $overallStats['kelas_empty']++;
            }

            // Get detail mapel mana yang belum lengkap
            $mapelDetail = [];
            if ($status !== 'complete') {
                $mapels = DB::table('kelas_mapel')
                    ->join('mapel', 'kelas_mapel.mapel_id', '=', 'mapel.id')
                    ->where('kelas_mapel.kelas_id', $kelas->id)
                    ->select('mapel.id', 'mapel.nama', 'mapel.kode')
                    ->get();

                foreach ($mapels as $mapel) {
                    // ✅ Hitung nilai per mapel (HANYA untuk siswa AKTIF)
                    $nilaiMapelCount = DB::table('nilai')
                        ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                        ->where('siswa.kelas_id', $kelas->id)
                        ->whereNull('siswa.deleted_at')
                        ->where('nilai.mapel_id', $mapel->id)
                        ->where('nilai.semester_id', $semesterId)
                        ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
                        ->whereNotNull('nilai.nilai')
                        ->count();

                    // ✅ Cap completion rate di 100%
                    $mapelCompletionRate = $totalSiswa > 0
                        ? min(round(($nilaiMapelCount / $totalSiswa) * 100, 1), 100)
                        : 0;

                    if ($mapelCompletionRate < 100) {
                        $mapelDetail[] = [
                            'mapel_id' => $mapel->id,
                            'mapel_nama' => $mapel->nama,
                            'mapel_kode' => $mapel->kode,
                            'nilai_terisi' => $nilaiMapelCount,
                            'nilai_expected' => $totalSiswa,
                            'completion_rate' => $mapelCompletionRate,
                            'missing_count' => max($totalSiswa - $nilaiMapelCount, 0),
                        ];
                    }
                }
            }

            $result[] = [
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $kelas->nama,
                    'tingkat' => $kelas->tingkat,
                    'section' => $kelas->section,
                ],
                'wali_kelas' => $waliKelas ? [
                    'guru_id' => $waliKelas->guru_id,
                    'guru_nama' => $waliKelas->guru_nama,
                ] : null,
                'total_siswa' => $totalSiswa,
                'total_mapel' => $totalMapel,
                'nilai_expected' => $nilaiExpected,
                'nilai_terisi' => min($nilaiTerisi, $nilaiExpected), // ✅ Cap agar tidak melebihi expected
                'nilai_missing' => max($nilaiExpected - $nilaiTerisi, 0),
                'completion_rate' => $completionRate,
                'status' => $status,
                'mapel_incomplete' => $mapelDetail,
            ];

            // Update overall stats
            $overallStats['total_kelas']++;
            $overallStats['total_siswa'] += $totalSiswa;
            $overallStats['total_nilai_expected'] += $nilaiExpected;
            $overallStats['total_nilai_terisi'] += min($nilaiTerisi, $nilaiExpected);
        }

        // ✅ Hitung overall completion rate dengan cap di 100%
        $overallStats['overall_completion_rate'] = $overallStats['total_nilai_expected'] > 0
            ? min(round(($overallStats['total_nilai_terisi'] / $overallStats['total_nilai_expected']) * 100, 1), 100)
            : 0;

        // Sort by completion rate (yang paling rendah dulu)
        usort($result, function($a, $b) {
            return $a['completion_rate'] <=> $b['completion_rate'];
        });

        return response()->json([
            'message' => 'Success',
            'semester' => [
                'id' => $semesterAktif->id,
                'nama' => $semesterAktif->nama,
            ],
            'tahun_ajaran' => [
                'id' => $tahunAktif->id,
                'nama' => $tahunAktif->nama,
            ],
            'overall_stats' => $overallStats,
            'kelas_list' => $result,
            'filters_applied' => [
                'kelas_id' => $kelasIdFilter,
                'guru_id' => $guruIdFilter,
                'completion_below' => $completionBelow,
            ]
        ]);
    }

    /**
     * Get detail siswa yang belum punya nilai di kelas tertentu
     */
    public function missingDetail(Request $request, $kelas_id)
    {
        $kelas = Kelas::findOrFail($kelas_id);

        // Get semester & tahun ajaran
        $tahunAjaranId = $request->query('tahun_ajaran_id');
        $semesterId = $request->query('semester_id');

        if (!$tahunAjaranId) {
            $tahunAktif = TahunAjaran::where('is_active', true)->first();
            $tahunAjaranId = $tahunAktif ? $tahunAktif->id : null;
        }

        if (!$semesterId) {
            $semesterAktif = Semester::where('tahun_ajaran_id', $tahunAjaranId)
                ->where('is_active', true)
                ->first();
            $semesterId = $semesterAktif ? $semesterAktif->id : null;
        }

        if (!$semesterId) {
            return response()->json(['message' => 'Semester tidak ditemukan'], 404);
        }

        $mapelIdFilter = $request->query('mapel_id');

        // ✅ Get HANYA siswa AKTIF di kelas (exclude soft deleted)
        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->where('is_alumni', false)
            ->whereNull('deleted_at')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nisn']);

        // Get mapel kelas
        $mapelQuery = DB::table('kelas_mapel')
            ->join('mapel', 'kelas_mapel.mapel_id', '=', 'mapel.id')
            ->where('kelas_mapel.kelas_id', $kelas_id);

        if ($mapelIdFilter) {
            $mapelQuery->where('mapel.id', $mapelIdFilter);
        }

        $mapelList = $mapelQuery->get(['mapel.id', 'mapel.nama', 'mapel.kode']);

        // ✅ Get nilai yang sudah ada (HANYA untuk siswa AKTIF)
        $nilaiExisting = DB::table('nilai')
            ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
            ->where('nilai.semester_id', $semesterId)
            ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
            ->whereNull('siswa.deleted_at')
            ->whereIn('nilai.siswa_id', $siswaList->pluck('id'))
            ->get(['nilai.siswa_id', 'nilai.mapel_id', 'nilai.nilai'])
            ->groupBy('siswa_id');

        $result = [];

        foreach ($siswaList as $siswa) {
            $nilaiSiswa = $nilaiExisting->get($siswa->id, collect());
            $mapelMissing = [];

            foreach ($mapelList as $mapel) {
                $hasNilai = $nilaiSiswa->where('mapel_id', $mapel->id)->isNotEmpty();

                if (!$hasNilai) {
                    $mapelMissing[] = [
                        'mapel_id' => $mapel->id,
                        'mapel_nama' => $mapel->nama,
                        'mapel_kode' => $mapel->kode,
                    ];
                }
            }

            // ✅ HANYA tampilkan siswa yang benar-benar ada mapel yang missing
            // ATAU jika sedang filter mapel tertentu
            if (!empty($mapelMissing) || $mapelIdFilter) {
                // ✅ Cap completion rate di 100%
                $completionRate = $mapelList->count() > 0
                    ? min(round(($nilaiSiswa->count() / $mapelList->count()) * 100, 1), 100)
                    : 0;

                $result[] = [
                    'siswa_id' => $siswa->id,
                    'siswa_nama' => $siswa->nama,
                    'siswa_nisn' => $siswa->nisn,
                    'nilai_terisi' => $nilaiSiswa->count(),
                    'nilai_expected' => $mapelList->count(),
                    'mapel_missing' => $mapelMissing,
                    'completion_rate' => $completionRate,
                ];
            }
        }

        return response()->json([
            'message' => 'Success',
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama,
            ],
            'total_siswa' => $siswaList->count(),
            'total_mapel' => $mapelList->count(),
            'siswa_with_missing_nilai' => $result,
        ]);
    }
}
