<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\NilaiSikap;
use App\Models\Siswa;
use App\Models\TahunAjaran;

class NilaiSikapController extends Controller
{
    /**
     * Get nilai sikap untuk semua siswa di kelas tertentu
     * GET /api/kelas/{kelas_id}/nilai-sikap?semester_id={semester_id}
     *
     * Middleware: auth:api, wali.kelas
     */
    public function index(Request $request, $kelas_id)
    {
        $semesterId = $request->query('semester_id');
        $tahunId = TahunAjaran::where('is_active', true)->value('id');

        if (!$semesterId) {
            return response()->json([
                'message' => 'semester_id parameter is required'
            ], 400);
        }

        // Verify semester belongs to active tahun ajaran
        $semester = DB::table('semester')
            ->where('id', $semesterId)
            ->where('tahun_ajaran_id', $tahunId)
            ->first();

        if (!$semester) {
            return response()->json([
                'message' => 'Semester tidak sesuai dengan tahun ajaran aktif'
            ], 400);
        }

        // Get all siswa in kelas
        $siswaList = Siswa::where('kelas_id', $kelas_id)
            ->orderBy('nama')
            ->get();

        // Get nilai sikap untuk siswa-siswa tersebut
        $nilaiSikap = NilaiSikap::with(['siswa', 'inputByGuru'])
            ->whereIn('siswa_id', $siswaList->pluck('id'))
            ->where('semester_id', $semesterId)
            ->where('tahun_ajaran_id', $tahunId)
            ->get()
            ->keyBy('siswa_id');

        // Format response
        $data = $siswaList->map(function($siswa) use ($nilaiSikap) {
            $nilai = $nilaiSikap->get($siswa->id);

            return [
                'siswa_id' => $siswa->id,
                'nama' => $siswa->nama,
                'nilai_sikap_id' => $nilai ? $nilai->id : null,
                'nilai' => $nilai ? $nilai->nilai : null,
                'nilai_label' => $nilai ? $nilai->nilai_label : null,
                'deskripsi' => $nilai ? $nilai->deskripsi : null,
                'input_by_guru' => $nilai && $nilai->inputByGuru ? [
                    'id' => $nilai->inputByGuru->id,
                    'nama' => $nilai->inputByGuru->nama,
                ] : null,
                'updated_at' => $nilai ? $nilai->updated_at : null,
            ];
        });

        return response()->json([
            'kelas_id' => $kelas_id,
            'semester_id' => $semesterId,
            'tahun_ajaran_id' => $tahunId,
            'data' => $data
        ]);
    }

    /**
     * Store atau update nilai sikap siswa
     * POST /api/kelas/{kelas_id}/nilai-sikap
     *
     * Body: {
     *   "siswa_id": 1,
     *   "semester_id": 1,
     *   "nilai": "A",
     *   "deskripsi": "Sikap baik, sopan santun"
     * }
     */
    public function store(Request $request, $kelas_id)
    {
        $validated = $request->validate([
            'siswa_id' => 'required|integer|exists:siswa,id',
            'semester_id' => 'required|integer|exists:semester,id',
            'nilai' => 'required|in:A,B,C,D,E',
            'deskripsi' => 'nullable|string|max:1000',
        ]);

        // Get active tahun ajaran
        $tahunId = TahunAjaran::where('is_active', true)->value('id');
        if (!$tahunId) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        // Verify semester belongs to active tahun ajaran
        $semester = DB::table('semester')
            ->where('id', $validated['semester_id'])
            ->where('tahun_ajaran_id', $tahunId)
            ->first();

        if (!$semester) {
            return response()->json(['message' => 'Semester tidak sesuai dengan tahun ajaran aktif'], 400);
        }

        // Verify siswa belongs to kelas
        $siswa = Siswa::where('id', $validated['siswa_id'])
            ->where('kelas_id', $kelas_id)
            ->first();

        if (!$siswa) {
            return response()->json(['message' => 'Siswa tidak ditemukan di kelas ini'], 404);
        }

        // Get guru_id from authenticated user
        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        try {
            DB::beginTransaction();

            // Upsert nilai sikap
            $nilaiSikap = NilaiSikap::updateOrCreate(
                [
                    'siswa_id' => $validated['siswa_id'],
                    'semester_id' => $validated['semester_id'],
                    'tahun_ajaran_id' => $tahunId,
                ],
                [
                    'nilai' => $validated['nilai'],
                    'deskripsi' => $validated['deskripsi'] ?? null,
                    'input_by_guru_id' => $guruId,
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Nilai sikap berhasil disimpan',
                'data' => $nilaiSikap->load(['siswa', 'semester', 'tahunAjaran', 'inputByGuru'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving nilai sikap: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk store nilai sikap untuk banyak siswa sekaligus
     * POST /api/kelas/{kelas_id}/nilai-sikap/bulk
     *
     * Body: {
     *   "semester_id": 1,
     *   "data": [
     *     {"siswa_id": 1, "nilai": "A", "deskripsi": "..."},
     *     {"siswa_id": 2, "nilai": "B", "deskripsi": "..."}
     *   ]
     * }
     */
    public function bulkStore(Request $request, $kelas_id)
    {
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semester,id',
            'data' => 'required|array|min:1',
            'data.*.siswa_id' => 'required|integer|exists:siswa,id',
            'data.*.nilai' => 'required|in:A,B,C,D,E',
            'data.*.deskripsi' => 'nullable|string|max:1000',
        ]);

        $tahunId = TahunAjaran::where('is_active', true)->value('id');
        if (!$tahunId) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        $success = 0;
        $failed = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($validated['data'] as $item) {
                // Verify siswa in kelas
                $siswa = Siswa::where('id', $item['siswa_id'])
                    ->where('kelas_id', $kelas_id)
                    ->first();

                if (!$siswa) {
                    $failed++;
                    $errors[] = "Siswa ID {$item['siswa_id']} tidak ditemukan di kelas ini";
                    continue;
                }

                NilaiSikap::updateOrCreate(
                    [
                        'siswa_id' => $item['siswa_id'],
                        'semester_id' => $validated['semester_id'],
                        'tahun_ajaran_id' => $tahunId,
                    ],
                    [
                        'nilai' => $item['nilai'],
                        'deskripsi' => $item['deskripsi'] ?? null,
                        'input_by_guru_id' => $guruId,
                    ]
                );

                $success++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk save completed',
                'summary' => [
                    'success' => $success,
                    'failed' => $failed,
                ],
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk saving nilai sikap: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete nilai sikap
     * DELETE /api/kelas/{kelas_id}/nilai-sikap/{id}
     */
    public function destroy($kelas_id, $id)
    {
        try {
            $nilaiSikap = NilaiSikap::findOrFail($id);

            // Verify siswa belongs to kelas
            if ($nilaiSikap->siswa->kelas_id != $kelas_id) {
                return response()->json([
                    'message' => 'Nilai sikap tidak ditemukan di kelas ini'
                ], 404);
            }

            $nilaiSikap->delete();

            return response()->json([
                'message' => 'Nilai sikap berhasil dihapus'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Nilai sikap tidak ditemukan'], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting nilai sikap: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get nilai sikap siswa (untuk siswa view sendiri)
     * GET /api/siswa/me/nilai-sikap
     *
     * Middleware: auth:siswa
     */
    public function siswaNilaiSikap(Request $request)
    {
        $siswa = Auth::guard('siswa')->user();

        $nilaiSikap = NilaiSikap::with(['semester.tahunAjaran', 'inputByGuru'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->get();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
            ],
            'data' => $nilaiSikap->map(function($n) {
                return [
                    'id' => $n->id,
                    'nilai' => $n->nilai,
                    'nilai_label' => $n->nilai_label,
                    'deskripsi' => $n->deskripsi,
                    'semester' => [
                        'id' => $n->semester->id,
                        'nama' => $n->semester->nama,
                    ],
                    'tahun_ajaran' => [
                        'id' => $n->tahunAjaran->id,
                        'nama' => $n->tahunAjaran->nama,
                    ],
                    'updated_at' => $n->updated_at,
                ];
            })
        ]);
    }
}
