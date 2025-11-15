<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Ketidakhadiran;
use App\Models\Siswa;
use App\Models\TahunAjaran;

class KetidakhadiranController extends Controller
{
    /**
     * Get ketidakhadiran untuk semua siswa di kelas tertentu
     * GET /api/kelas/{kelas_id}/ketidakhadiran?semester_id={semester_id}
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

        // Get ketidakhadiran untuk siswa-siswa tersebut
        $ketidakhadiran = Ketidakhadiran::with(['siswa', 'inputByGuru'])
            ->whereIn('siswa_id', $siswaList->pluck('id'))
            ->where('semester_id', $semesterId)
            ->where('tahun_ajaran_id', $tahunId)
            ->get()
            ->keyBy('siswa_id');

        // Format response
        $data = $siswaList->map(function($siswa) use ($ketidakhadiran) {
            $record = $ketidakhadiran->get($siswa->id);

            return [
                'siswa_id' => $siswa->id,
                'nama' => $siswa->nama,
                'ketidakhadiran_id' => $record ? $record->id : null,
                'ijin' => $record ? $record->ijin : 0,
                'sakit' => $record ? $record->sakit : 0,
                'alpa' => $record ? $record->alpa : 0,
                'total' => $record ? $record->total : 0,
                'catatan' => $record ? $record->catatan : null,
                'input_by_guru' => $record && $record->inputByGuru ? [
                    'id' => $record->inputByGuru->id,
                    'nama' => $record->inputByGuru->nama,
                ] : null,
                'updated_at' => $record ? $record->updated_at : null,
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
     * Store atau update ketidakhadiran siswa
     * POST /api/kelas/{kelas_id}/ketidakhadiran
     *
     * Body: {
     *   "siswa_id": 1,
     *   "semester_id": 1,
     *   "ijin": 2,
     *   "sakit": 3,
     *   "alpa": 0,
     *   "catatan": "..."
     * }
     */
    public function store(Request $request, $kelas_id)
    {
        $validated = $request->validate([
            'siswa_id' => 'required|integer|exists:siswa,id',
            'semester_id' => 'required|integer|exists:semester,id',
            'ijin' => 'required|integer|min:0',
            'sakit' => 'required|integer|min:0',
            'alpa' => 'required|integer|min:0',
            'catatan' => 'nullable|string|max:1000',
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

            // Upsert ketidakhadiran
            $ketidakhadiran = Ketidakhadiran::updateOrCreate(
                [
                    'siswa_id' => $validated['siswa_id'],
                    'semester_id' => $validated['semester_id'],
                    'tahun_ajaran_id' => $tahunId,
                ],
                [
                    'ijin' => $validated['ijin'],
                    'sakit' => $validated['sakit'],
                    'alpa' => $validated['alpa'],
                    'catatan' => $validated['catatan'] ?? null,
                    'input_by_guru_id' => $guruId,
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Ketidakhadiran berhasil disimpan',
                'data' => $ketidakhadiran->load(['siswa', 'semester', 'tahunAjaran', 'inputByGuru'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving ketidakhadiran: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk store ketidakhadiran untuk banyak siswa sekaligus
     * POST /api/kelas/{kelas_id}/ketidakhadiran/bulk
     *
     * Body: {
     *   "semester_id": 1,
     *   "data": [
     *     {"siswa_id": 1, "ijin": 2, "sakit": 1, "alpa": 0},
     *     {"siswa_id": 2, "ijin": 0, "sakit": 3, "alpa": 1}
     *   ]
     * }
     */
    public function bulkStore(Request $request, $kelas_id)
    {
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semester,id',
            'data' => 'required|array|min:1',
            'data.*.siswa_id' => 'required|integer|exists:siswa,id',
            'data.*.ijin' => 'required|integer|min:0',
            'data.*.sakit' => 'required|integer|min:0',
            'data.*.alpa' => 'required|integer|min:0',
            'data.*.catatan' => 'nullable|string|max:1000',
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

                Ketidakhadiran::updateOrCreate(
                    [
                        'siswa_id' => $item['siswa_id'],
                        'semester_id' => $validated['semester_id'],
                        'tahun_ajaran_id' => $tahunId,
                    ],
                    [
                        'ijin' => $item['ijin'],
                        'sakit' => $item['sakit'],
                        'alpa' => $item['alpa'],
                        'catatan' => $item['catatan'] ?? null,
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
            Log::error('Error bulk saving ketidakhadiran: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update increment ketidakhadiran (untuk tambah 1-1)
     * POST /api/kelas/{kelas_id}/ketidakhadiran/{siswa_id}/increment
     *
     * Body: {
     *   "semester_id": 1,
     *   "type": "ijin" // atau "sakit" atau "alpa"
     * }
     */
    public function increment(Request $request, $kelas_id, $siswa_id)
    {
        $validated = $request->validate([
            'semester_id' => 'required|integer|exists:semester,id',
            'type' => 'required|in:ijin,sakit,alpa',
        ]);

        $tahunId = TahunAjaran::where('is_active', true)->value('id');
        if (!$tahunId) {
            return response()->json(['message' => 'Tidak ada tahun ajaran aktif'], 400);
        }

        // Verify siswa belongs to kelas
        $siswa = Siswa::where('id', $siswa_id)
            ->where('kelas_id', $kelas_id)
            ->first();

        if (!$siswa) {
            return response()->json(['message' => 'Siswa tidak ditemukan di kelas ini'], 404);
        }

        $user = Auth::guard('api')->user();
        $guruId = $user->guru ? $user->guru->id : null;

        try {
            DB::beginTransaction();

            $ketidakhadiran = Ketidakhadiran::firstOrCreate(
                [
                    'siswa_id' => $siswa_id,
                    'semester_id' => $validated['semester_id'],
                    'tahun_ajaran_id' => $tahunId,
                ],
                [
                    'ijin' => 0,
                    'sakit' => 0,
                    'alpa' => 0,
                    'input_by_guru_id' => $guruId,
                ]
            );

            // Increment the specified type
            $ketidakhadiran->increment($validated['type']);
            $ketidakhadiran->input_by_guru_id = $guruId;
            $ketidakhadiran->save();

            DB::commit();

            return response()->json([
                'message' => ucfirst($validated['type']) . ' berhasil ditambah',
                'data' => $ketidakhadiran->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error incrementing ketidakhadiran: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete ketidakhadiran
     * DELETE /api/kelas/{kelas_id}/ketidakhadiran/{id}
     */
    public function destroy($kelas_id, $id)
    {
        try {
            $ketidakhadiran = Ketidakhadiran::findOrFail($id);

            // Verify siswa belongs to kelas
            if ($ketidakhadiran->siswa->kelas_id != $kelas_id) {
                return response()->json([
                    'message' => 'Data ketidakhadiran tidak ditemukan di kelas ini'
                ], 404);
            }

            $ketidakhadiran->delete();

            return response()->json([
                'message' => 'Data ketidakhadiran berhasil dihapus'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Data ketidakhadiran tidak ditemukan'], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting ketidakhadiran: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ketidakhadiran siswa (untuk siswa view sendiri)
     * GET /api/siswa/me/ketidakhadiran
     *
     * Middleware: auth:siswa
     */
    public function siswaKetidakhadiran(Request $request)
    {
        $siswa = Auth::guard('siswa')->user();

        $ketidakhadiran = Ketidakhadiran::with(['semester.tahunAjaran', 'inputByGuru'])
            ->where('siswa_id', $siswa->id)
            ->orderByDesc('tahun_ajaran_id')
            ->orderByDesc('semester_id')
            ->get();

        return response()->json([
            'siswa' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
            ],
            'data' => $ketidakhadiran->map(function($k) {
                return [
                    'id' => $k->id,
                    'ijin' => $k->ijin,
                    'sakit' => $k->sakit,
                    'alpa' => $k->alpa,
                    'total' => $k->total,
                    'catatan' => $k->catatan,
                    'semester' => [
                        'id' => $k->semester->id,
                        'nama' => $k->semester->nama,
                    ],
                    'tahun_ajaran' => [
                        'id' => $k->tahunAjaran->id,
                        'nama' => $k->tahunAjaran->nama,
                    ],
                    'updated_at' => $k->updated_at,
                ];
            })
        ]);
    }
}
