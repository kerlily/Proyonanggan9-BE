<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WaliKelas;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class WaliKelasController extends Controller
{
    // list semua assignment (opsional filter tahun)
    public function index(Request $request)
    {
        $tahunId = $request->query('tahun_ajaran_id');
        $q = WaliKelas::with(['guru.user','kelas','tahunAjaran'])
            ->orderBy('kelas_id')
            ->orderByDesc('is_primary'); // primary dulu

        if ($tahunId) $q->where('tahun_ajaran_id', $tahunId);

        return response()->json($q->get());
    }

    // show semua wali untuk kelas tertentu & tahun (default tahun aktif)
    public function showByKelas(Request $request, $kelas_id)
    {
        $tahunId = $request->query('tahun_ajaran_id') ?? TahunAjaran::where('is_active', true)->value('id');

        $records = WaliKelas::with(['guru.user','kelas','tahunAjaran'])
            ->where('kelas_id', $kelas_id)
            ->where('tahun_ajaran_id', $tahunId)
            ->orderByDesc('is_primary') // primary dulu
            ->get();

        if ($records->isEmpty()) {
            return response()->json(['message' => 'No wali assigned for this class/year'], 404);
        }

        return response()->json([
            'primary' => $records->firstWhere('is_primary', true),
            'additional' => $records->where('is_primary', false)->values(),
            'all' => $records
        ]);
    }

    // assign wali kelas (bisa multiple)
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'guru_id' => ['required','integer','exists:guru,id'],
            'kelas_id' => ['required','integer','exists:kelas,id'],
            'tahun_ajaran_id' => ['nullable','integer','exists:tahun_ajaran,id'],
            'is_primary' => ['nullable','boolean'],
        ]);

        $tahunId = $validated['tahun_ajaran_id'] ?? TahunAjaran::where('is_active', true)->value('id');
        if (! $tahunId) {
            return response()->json(['message' => 'No active academic year and no tahun_ajaran_id provided'], 422);
        }

        $isPrimary = $validated['is_primary'] ?? false;

        DB::beginTransaction();
        try {
            // Cek apakah guru sudah di-assign di kelas ini
            $existing = WaliKelas::where('kelas_id', $validated['kelas_id'])
                ->where('tahun_ajaran_id', $tahunId)
                ->where('guru_id', $validated['guru_id'])
                ->first();

            if ($existing) {
                // Update is_primary jika berbeda
                if ($existing->is_primary != $isPrimary) {
                    $existing->is_primary = $isPrimary;
                    $existing->save();
                }

                // Jika set sebagai primary, unset primary lainnya
                if ($isPrimary) {
                    WaliKelas::where('kelas_id', $validated['kelas_id'])
                        ->where('tahun_ajaran_id', $tahunId)
                        ->where('id', '!=', $existing->id)
                        ->update(['is_primary' => false]);
                }

                DB::commit();
                return response()->json([
                    'message' => 'Wali kelas updated successfully',
                    'data' => $existing
                ], 200);
            }

            // Jika akan set sebagai primary, unset primary yang lama
            if ($isPrimary) {
                WaliKelas::where('kelas_id', $validated['kelas_id'])
                    ->where('tahun_ajaran_id', $tahunId)
                    ->update(['is_primary' => false]);
            }

            // Create new assignment
            $wk = WaliKelas::create([
                'guru_id' => $validated['guru_id'],
                'kelas_id' => $validated['kelas_id'],
                'tahun_ajaran_id' => $tahunId,
                'is_primary' => $isPrimary,
            ]);

            DB::commit();
            return response()->json([
                'message' => 'Wali kelas assigned successfully',
                'data' => $wk
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    // unassign - delete record sepenuhnya
    public function unassign(Request $request, $id)
    {
        $wk = WaliKelas::find($id);
        if (! $wk) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Cek apakah ini wali primary
        if ($wk->is_primary) {
            // Cek apakah ada wali tambahan
            $hasAdditional = WaliKelas::where('kelas_id', $wk->kelas_id)
                ->where('tahun_ajaran_id', $wk->tahun_ajaran_id)
                ->where('id', '!=', $wk->id)
                ->exists();

            if ($hasAdditional) {
                return response()->json([
                    'message' => 'Tidak bisa hapus wali utama jika masih ada wali tambahan. Promosikan wali tambahan terlebih dahulu atau hapus semua wali tambahan.'
                ], 422);
            }
        }

        $wk->delete();

        return response()->json(['message' => 'Wali kelas berhasil dihapus'], 200);
    }

    public function showByGuru(Request $request)
    {
        $user = auth()->guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json(['message' => 'Not a guru'], 403);
        }

        $tahunId = $request->query('tahun_ajaran_id')
            ?? TahunAjaran::where('is_active', true)->value('id');

        $waliKelas = WaliKelas::with(['kelas', 'tahunAjaran'])
            ->where('guru_id', $guru->id)
            ->where('tahun_ajaran_id', $tahunId)
            ->orderByDesc('is_primary')
            ->get();

        return response()->json($waliKelas);
    }

public function getNilaiDetailHistory(Request $request)
{
    $user = auth()->guard('api')->user();
    $guru = $user->guru;

    if (!$guru) {
        return response()->json(['message' => 'Not a guru'], 403);
    }

    $kelasId = $request->query('kelas_id');

    if (!$kelasId) {
        return response()->json(['message' => 'kelas_id parameter required'], 400);
    }

    $kelas = \App\Models\Kelas::findOrFail($kelasId);

    // Verify guru pernah jadi wali kelas ini
    $isWali = WaliKelas::where('guru_id', $guru->id)
        ->where('kelas_id', $kelasId)
        ->exists();

    if (!$isWali && $user->role !== 'admin') {
        return response()->json(['message' => 'Forbidden - Bukan wali kelas ini'], 403);
    }

    // Get semua tahun ajaran dimana guru ini wali kelas ini
    $tahunAjaranIds = WaliKelas::where('guru_id', $guru->id)
        ->where('kelas_id', $kelasId)
        ->pluck('tahun_ajaran_id')
        ->unique();

    $result = [];

    foreach ($tahunAjaranIds as $tahunId) {
        $tahunAjaran = \App\Models\TahunAjaran::find($tahunId);

        if (!$tahunAjaran) continue;

        // Get semua struktur nilai di tahun ini
        $strukturList = \App\Models\StrukturNilaiMapel::with(['mapel', 'semester'])
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunId)
            ->get();

        $strukturData = [];

        foreach ($strukturList as $struktur) {
            // Hitung progress
            $totalSiswa = \DB::table('siswa')->where('kelas_id', $kelasId)->count();

            // Hitung total kolom yang harus diisi
            $lingkup = $struktur->struktur['lingkup_materi'] ?? [];
            $totalKolom = 2; // ASLIM + ASAS
            foreach ($lingkup as $lm) {
                $formatif = $lm['formatif'] ?? [];
                $totalKolom += count($formatif);
            }

            // Hitung siswa yang sudah selesai (100%)
            $siswaSelesai = \DB::table('nilai_detail')
                ->where('struktur_nilai_mapel_id', $struktur->id)
                ->select('siswa_id', \DB::raw('COUNT(*) as nilai_count'))
                ->groupBy('siswa_id')
                ->having('nilai_count', '>=', $totalKolom)
                ->count();

            $completionRate = $totalSiswa > 0 ? round(($siswaSelesai / $totalSiswa) * 100) : 0;

            $strukturData[] = [
                'struktur_id' => $struktur->id,
                'mapel' => [
                    'id' => $struktur->mapel->id,
                    'nama' => $struktur->mapel->nama,
                    'kode' => $struktur->mapel->kode,
                ],
                'semester' => [
                    'id' => $struktur->semester->id,
                    'nama' => $struktur->semester->nama,
                ],
                'total_siswa' => $totalSiswa,
                'total_kolom' => $totalKolom,
                'siswa_selesai' => $siswaSelesai,
                'completion_rate' => $completionRate,
                'created_at' => $struktur->created_at,
            ];
        }

        $result[] = [
            'tahun_ajaran' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
                'is_active' => $tahunAjaran->is_active,
            ],
            'struktur_nilai' => $strukturData,
        ];
    }

    // Sort by tahun ajaran terbaru dulu
    usort($result, function($a, $b) {
        return $b['tahun_ajaran']['id'] <=> $a['tahun_ajaran']['id'];
    });

    return response()->json([
        'kelas' => [
            'id' => $kelas->id,
            'nama' => $kelas->nama,
            'tingkat' => $kelas->tingkat,
            'section' => $kelas->section,
        ],
        'guru' => [
            'id' => $guru->id,
            'nama' => $guru->nama,
        ],
        'tahun_ajaran_list' => $result,
        'total_tahun_ajaran' => count($result),
    ]);
}
    public function getNilaiHistory(Request $request)
    {
        $user = auth()->guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json(['message' => 'Not a guru'], 403);
        }

        $kelasId = $request->query('kelas_id');

        if (!$kelasId) {
            return response()->json(['message' => 'kelas_id parameter required'], 400);
        }

        $kelas = \App\Models\Kelas::findOrFail($kelasId);

        // Verify guru pernah jadi wali kelas ini
        $isWali = WaliKelas::where('guru_id', $guru->id)
            ->where('kelas_id', $kelasId)
            ->exists();

        if (!$isWali && $user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden - Bukan wali kelas ini'], 403);
        }

        // Get semua tahun ajaran dimana guru ini wali kelas ini
        $tahunAjaranIds = WaliKelas::where('guru_id', $guru->id)
            ->where('kelas_id', $kelasId)
            ->pluck('tahun_ajaran_id')
            ->unique();

        $result = [];

        foreach ($tahunAjaranIds as $tahunId) {
            $tahunAjaran = \App\Models\TahunAjaran::find($tahunId);

            if (!$tahunAjaran) continue;

            // Get semua semester di tahun ajaran ini
            $semesters = \App\Models\Semester::where('tahun_ajaran_id', $tahunId)
                ->orderBy('id')
                ->get();

            $semesterData = [];

            foreach ($semesters as $semester) {
                // Get nilai akhir per mapel di semester ini
                $nilaiPerMapel = DB::table('nilai')
                    ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                    ->join('mapel', 'nilai.mapel_id', '=', 'mapel.id')
                    ->where('siswa.kelas_id', $kelasId)
                    ->where('nilai.semester_id', $semester->id)
                    ->where('nilai.tahun_ajaran_id', $tahunId)
                    ->select(
                        'mapel.id as mapel_id',
                        'mapel.nama as mapel_nama',
                        'mapel.kode as mapel_kode',
                        DB::raw('COUNT(DISTINCT nilai.siswa_id) as siswa_dengan_nilai'),
                        DB::raw('MAX(nilai.nilai) as nilai_tertinggi'),
                        DB::raw('MIN(nilai.nilai) as nilai_terendah'),
                        DB::raw('AVG(nilai.nilai) as rata_rata')
                    )
                    ->groupBy('mapel.id', 'mapel.nama', 'mapel.kode')
                    ->get();

                if ($nilaiPerMapel->isEmpty()) continue;

                // Hitung total siswa di kelas
                $totalSiswa = DB::table('siswa')->where('kelas_id', $kelasId)->count();

                $mapelList = [];
                foreach ($nilaiPerMapel as $item) {
                    $completionRate = $totalSiswa > 0
                        ? round(($item->siswa_dengan_nilai / $totalSiswa) * 100)
                        : 0;

                    $mapelList[] = [
                        'mapel' => [
                            'id' => $item->mapel_id,
                            'nama' => $item->mapel_nama,
                            'kode' => $item->mapel_kode,
                        ],
                        'total_siswa' => $totalSiswa,
                        'siswa_dengan_nilai' => $item->siswa_dengan_nilai,
                        'completion_rate' => $completionRate,
                        'nilai_tertinggi' => $item->nilai_tertinggi,
                        'nilai_terendah' => $item->nilai_terendah,
                        'rata_rata' => round($item->rata_rata, 2),
                    ];
                }

                if (!empty($mapelList)) {
                    $semesterData[] = [
                        'semester' => [
                            'id' => $semester->id,
                            'nama' => $semester->nama,
                        ],
                        'mapel_list' => $mapelList,
                    ];
                }
            }

            if (!empty($semesterData)) {
                $result[] = [
                    'tahun_ajaran' => [
                        'id' => $tahunAjaran->id,
                        'nama' => $tahunAjaran->nama,
                        'is_active' => $tahunAjaran->is_active,
                    ],
                    'semester_list' => $semesterData,
                ];
            }
        }

        // Sort by tahun ajaran terbaru dulu
        usort($result, function($a, $b) {
            return $b['tahun_ajaran']['id'] <=> $a['tahun_ajaran']['id'];
        });

        return response()->json([
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama,
                'tingkat' => $kelas->tingkat,
                'section' => $kelas->section,
            ],
            'guru' => [
                'id' => $guru->id,
                'nama' => $guru->nama,
            ],
            'tahun_ajaran_list' => $result,
            'total_tahun_ajaran' => count($result),
        ]);
    }

    /**
     * Get detail nilai siswa per mapel untuk history
     * GET /api/wali-kelas/nilai-history/detail?kelas_id=1&mapel_id=1&semester_id=1&tahun_ajaran_id=1
     */
    public function getNilaiHistoryDetail(Request $request)
    {
        $user = auth()->guard('api')->user();
        $guru = $user->guru;

        if (!$guru) {
            return response()->json(['message' => 'Not a guru'], 403);
        }

        $validated = $request->validate([
            'kelas_id' => 'required|integer|exists:kelas,id',
            'mapel_id' => 'required|integer|exists:mapel,id',
            'semester_id' => 'required|integer|exists:semester,id',
            'tahun_ajaran_id' => 'required|integer|exists:tahun_ajaran,id',
        ]);

        $kelasId = $validated['kelas_id'];
        $mapelId = $validated['mapel_id'];
        $semesterId = $validated['semester_id'];
        $tahunAjaranId = $validated['tahun_ajaran_id'];

        // Verify guru pernah jadi wali kelas ini
        $isWali = WaliKelas::where('guru_id', $guru->id)
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->exists();

        if (!$isWali && $user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden - Bukan wali kelas ini'], 403);
        }

        $kelas = \App\Models\Kelas::findOrFail($kelasId);
        $mapel = \App\Models\Mapel::findOrFail($mapelId);
        $semester = \App\Models\Semester::findOrFail($semesterId);
        $tahunAjaran = \App\Models\TahunAjaran::findOrFail($tahunAjaranId);

        // Get semua siswa di kelas (berdasarkan riwayat kelas untuk tahun ajaran tersebut)
        $siswaIds = DB::table('riwayat_kelas')
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->pluck('siswa_id');

        // Jika tidak ada riwayat, gunakan siswa yang saat ini di kelas
        if ($siswaIds->isEmpty()) {
            $siswaIds = DB::table('siswa')
                ->where('kelas_id', $kelasId)
                ->pluck('id');
        }

        $siswaList = \App\Models\Siswa::whereIn('id', $siswaIds)
            ->orderBy('nama')
            ->get(['id', 'nama']);

        // Get nilai untuk mapel dan semester ini
        $nilaiData = DB::table('nilai')
            ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
            ->whereIn('nilai.siswa_id', $siswaIds)
            ->where('nilai.mapel_id', $mapelId)
            ->where('nilai.semester_id', $semesterId)
            ->where('nilai.tahun_ajaran_id', $tahunAjaranId)
            ->select(
                'nilai.id as nilai_id',
                'siswa.id as siswa_id',
                'siswa.nama as siswa_nama',
                'nilai.nilai',
                'nilai.catatan',
                'nilai.updated_at'
            )
            ->get()
            ->keyBy('siswa_id');

        // Gabungkan data siswa dengan nilai
        $result = $siswaList->map(function($siswa) use ($nilaiData) {
            $nilai = $nilaiData->get($siswa->id);

            return [
                'siswa_id' => $siswa->id,
                'siswa_nama' => $siswa->nama,
                'nilai' => $nilai ? $nilai->nilai : null,
                'catatan' => $nilai ? $nilai->catatan : null,
                'updated_at' => $nilai ? $nilai->updated_at : null,
                'has_nilai' => $nilai !== null,
            ];
        });

        // Hitung statistik
        $nilaiList = $result->where('has_nilai', true)->pluck('nilai')->filter();
        $stats = [
            'total_siswa' => $siswaList->count(),
            'siswa_dengan_nilai' => $nilaiList->count(),
            'siswa_tanpa_nilai' => $siswaList->count() - $nilaiList->count(),
            'completion_rate' => $siswaList->count() > 0
                ? round(($nilaiList->count() / $siswaList->count()) * 100)
                : 0,
            'nilai_tertinggi' => $nilaiList->isNotEmpty() ? $nilaiList->max() : null,
            'nilai_terendah' => $nilaiList->isNotEmpty() ? $nilaiList->min() : null,
            'rata_rata' => $nilaiList->isNotEmpty() ? round($nilaiList->avg(), 2) : null,
        ];

        return response()->json([
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama,
            ],
            'mapel' => [
                'id' => $mapel->id,
                'nama' => $mapel->nama,
                'kode' => $mapel->kode,
            ],
            'semester' => [
                'id' => $semester->id,
                'nama' => $semester->nama,
            ],
            'tahun_ajaran' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
            ],
            'statistics' => $stats,
            'siswa_list' => $result->values(),
        ]);
    }
}
