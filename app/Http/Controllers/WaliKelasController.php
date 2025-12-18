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

    /**
 * Get semua nilai detail untuk kelas yang diajar guru (semua tahun ajaran)
 * GET /api/wali-kelas/nilai-detail?kelas_id=1
 *
 * Response format:
 * {
 *   "kelas": {...},
 *   "tahun_ajaran_list": [
 *     {
 *       "tahun_ajaran": {...},
 *       "struktur_nilai": [
 *         {
 *           "mapel": {...},
 *           "semester": {...},
 *           "struktur_id": 1,
 *           "total_siswa": 30,
 *           "siswa_selesai": 25,
 *           "completion_rate": 83
 *         }
 *       ]
 *     }
 *   ]
 * }
 */
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
}
