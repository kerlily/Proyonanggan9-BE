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
        $completionBelow = $request->query('completion_below'); // misal: 50 (untuk < 50%)

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

            // Get total siswa di kelas (dari riwayat_kelas atau siswa aktif)
            $totalSiswa = DB::table('riwayat_kelas')
                ->where('kelas_id', $kelas->id)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->count();

            // Jika tidak ada riwayat, pakai siswa yang aktif sekarang
            if ($totalSiswa == 0) {
                $totalSiswa = DB::table('siswa')
                    ->where('kelas_id', $kelas->id)
                    ->where('is_alumni', false)
                    ->count();
            }

            // Get total mapel yang di-assign ke kelas ini
            $totalMapel = DB::table('kelas_mapel')
                ->where('kelas_id', $kelas->id)
                ->count();

            // Expected total nilai = siswa x mapel
            $nilaiExpected = $totalSiswa * $totalMapel;

            // Get total nilai yang sudah terisi
            $nilaiTerisi = DB::table('nilai')
                ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                ->where('siswa.kelas_id', $kelas->id)
                ->where('nilai.semester_id', $semesterId)
                ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
                ->whereNotNull('nilai.nilai')
                ->count();

            // Hitung completion rate
            $completionRate = $nilaiExpected > 0
                ? round(($nilaiTerisi / $nilaiExpected) * 100, 1)
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
                    $nilaiMapelCount = DB::table('nilai')
                        ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                        ->where('siswa.kelas_id', $kelas->id)
                        ->where('nilai.mapel_id', $mapel->id)
                        ->where('nilai.semester_id', $semesterId)
                        ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
                        ->whereNotNull('nilai.nilai')
                        ->count();

                    $mapelCompletionRate = $totalSiswa > 0
                        ? round(($nilaiMapelCount / $totalSiswa) * 100, 1)
                        : 0;

                    if ($mapelCompletionRate < 100) {
                        $mapelDetail[] = [
                            'mapel_id' => $mapel->id,
                            'mapel_nama' => $mapel->nama,
                            'mapel_kode' => $mapel->kode,
                            'nilai_terisi' => $nilaiMapelCount,
                            'nilai_expected' => $totalSiswa,
                            'completion_rate' => $mapelCompletionRate,
                            'missing_count' => $totalSiswa - $nilaiMapelCount,
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
                'nilai_terisi' => $nilaiTerisi,
                'nilai_missing' => $nilaiExpected - $nilaiTerisi,
                'completion_rate' => $completionRate,
                'status' => $status,
                'mapel_incomplete' => $mapelDetail,
            ];

            // Update overall stats
            $overallStats['total_kelas']++;
            $overallStats['total_siswa'] += $totalSiswa;
            $overallStats['total_nilai_expected'] += $nilaiExpected;
            $overallStats['total_nilai_terisi'] += $nilaiTerisi;
        }

        // Hitung overall completion rate
        $overallStats['overall_completion_rate'] = $overallStats['total_nilai_expected'] > 0
            ? round(($overallStats['total_nilai_terisi'] / $overallStats['total_nilai_expected']) * 100, 1)
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
     * GET /api/admin/nilai-akhir/monitoring/kelas/{kelas_id}/missing
     *
     * Query params:
     * - semester_id (optional)
     * - tahun_ajaran_id (optional)
     * - mapel_id (optional, filter mapel tertentu)
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

        // Get siswa di kelas
        $siswaList = DB::table('siswa')
            ->where('kelas_id', $kelas_id)
            ->where('is_alumni', false)
            ->orderBy('nama')
            ->get(['id', 'nama']);

        // Get mapel kelas
        $mapelQuery = DB::table('kelas_mapel')
            ->join('mapel', 'kelas_mapel.mapel_id', '=', 'mapel.id')
            ->where('kelas_mapel.kelas_id', $kelas_id);

        if ($mapelIdFilter) {
            $mapelQuery->where('mapel.id', $mapelIdFilter);
        }

        $mapelList = $mapelQuery->get(['mapel.id', 'mapel.nama', 'mapel.kode']);

        // Get nilai yang sudah ada
        $nilaiExisting = DB::table('nilai')
            ->where('semester_id', $semesterId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereIn('siswa_id', $siswaList->pluck('id'))
            ->get(['siswa_id', 'mapel_id', 'nilai'])
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

            if (!empty($mapelMissing) || !$mapelIdFilter) {
                $result[] = [
                    'siswa_id' => $siswa->id,
                    'siswa_nama' => $siswa->nama,
                    'nilai_terisi' => $nilaiSiswa->count(),
                    'nilai_expected' => $mapelList->count(),
                    'mapel_missing' => $mapelMissing,
                    'completion_rate' => $mapelList->count() > 0
                        ? round(($nilaiSiswa->count() / $mapelList->count()) * 100, 1)
                        : 0,
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
