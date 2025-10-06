<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Mapel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MapelController extends Controller
{
    /**
     * List semua mapel dengan pagination (Admin)
     * GET /api/admin/mapel?search=matematika&per_page=20
     *
     * Query params:
     * - search: cari berdasarkan nama atau kode
     * - per_page: jumlah data per halaman (default: 20)
     */
    public function index(Request $request)
    {
        try {
            $search = $request->query('search');
            $perPage = (int) $request->query('per_page', 20);

            $query = Mapel::query();

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                      ->orWhere('kode', 'like', "%{$search}%");
                });
            }

            $mapels = $query->orderBy('nama')->paginate($perPage);

            return response()->json($mapels);
        } catch (\Exception $e) {
            Log::error('Error getting mapel list: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show detail single mapel
     * GET /api/admin/mapel/{id}
     *
     * Menampilkan info mapel + di kelas mana saja mapel ini dipakai
     */
    public function show($id)
    {
        try {
            $mapel = Mapel::with('kelas')->findOrFail($id);

            // Count berapa banyak nilai yang pakai mapel ini
            $nilaiCount = DB::table('nilai')->where('mapel_id', $id)->count();

            return response()->json([
                'mapel' => [
                    'id' => $mapel->id,
                    'nama' => $mapel->nama,
                    'kode' => $mapel->kode,
                    'created_at' => $mapel->created_at,
                    'updated_at' => $mapel->updated_at,
                ],
                'usage' => [
                    'used_in_kelas_count' => $mapel->kelas->count(),
                    'total_nilai_count' => $nilaiCount,
                ],
                'kelas_list' => $mapel->kelas->map(function($k) {
                    return [
                        'id' => $k->id,
                        'nama' => $k->nama,
                        'tingkat' => $k->tingkat,
                        'section' => $k->section,
                        'assigned_at' => $k->pivot->created_at,
                    ];
                })
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mapel tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error showing mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create mapel baru
     * POST /api/admin/mapel
     * Body: {
     *   "nama": "Matematika",
     *   "kode": "MTK"
     * }
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255|unique:mapel,nama',
            'kode' => 'nullable|string|max:50|unique:mapel,kode',
        ]);

        try {
            DB::beginTransaction();

            $mapel = Mapel::create([
                'nama' => $request->nama,
                'kode' => $request->kode,
            ]);

            DB::commit();

            Log::info('Mapel created', [
                'admin_id' => auth()->guard('api')->id(),
                'mapel_id' => $mapel->id,
                'mapel_nama' => $mapel->nama,
            ]);

            return response()->json([
                'message' => 'Mapel berhasil dibuat',
                'mapel' => $mapel
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update mapel
     * PUT /api/admin/mapel/{id}
     * Body: {
     *   "nama": "Matematika Dasar",
     *   "kode": "MTK-DS"
     * }
     */
    public function update(Request $request, $id)
    {
        try {
            $mapel = Mapel::findOrFail($id);

            $request->validate([
                'nama' => 'sometimes|string|max:255|unique:mapel,nama,' . $id,
                'kode' => 'nullable|string|max:50|unique:mapel,kode,' . $id,
            ]);

            DB::beginTransaction();

            $oldData = [
                'nama' => $mapel->nama,
                'kode' => $mapel->kode,
            ];

            $mapel->update($request->only(['nama', 'kode']));

            DB::commit();

            Log::info('Mapel updated', [
                'admin_id' => auth()->guard('api')->id(),
                'mapel_id' => $mapel->id,
                'old_data' => $oldData,
                'new_data' => $request->only(['nama', 'kode']),
            ]);

            return response()->json([
                'message' => 'Mapel berhasil diupdate',
                'mapel' => $mapel
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mapel tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete mapel
     * DELETE /api/admin/mapel/{id}
     *
     * HATI-HATI: Mapel hanya bisa dihapus jika:
     * 1. Tidak digunakan di kelas manapun
     * 2. Tidak ada data nilai yang pakai mapel ini
     */
    public function destroy($id)
    {
        try {
            $mapel = Mapel::with('kelas')->findOrFail($id);

            // Cek apakah mapel sedang digunakan di kelas
            if ($mapel->kelas->count() > 0) {
                return response()->json([
                    'message' => "Mapel '{$mapel->nama}' sedang digunakan di {$mapel->kelas->count()} kelas. Hapus assignment dari kelas terlebih dahulu.",
                    'kelas_list' => $mapel->kelas->pluck('nama'),
                    'action_required' => 'Lepas mapel dari kelas-kelas tersebut terlebih dahulu'
                ], 422);
            }

            // Cek apakah ada nilai yang menggunakan mapel ini
            $nilaiCount = DB::table('nilai')->where('mapel_id', $id)->count();
            if ($nilaiCount > 0) {
                return response()->json([
                    'message' => "Mapel '{$mapel->nama}' memiliki {$nilaiCount} data nilai siswa. Tidak bisa dihapus.",
                    'action_required' => 'Hapus semua data nilai yang menggunakan mapel ini terlebih dahulu, atau biarkan mapel tetap ada'
                ], 422);
            }

            DB::beginTransaction();

            $mapelData = [
                'id' => $mapel->id,
                'nama' => $mapel->nama,
                'kode' => $mapel->kode,
            ];

            $mapel->delete();

            DB::commit();

            Log::info('Mapel deleted', [
                'admin_id' => auth()->guard('api')->id(),
                'mapel_data' => $mapelData,
            ]);

            return response()->json([
                'message' => "Mapel '{$mapelData['nama']}' berhasil dihapus"
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mapel tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list semua mapel tanpa pagination (untuk dropdown)
     * GET /api/mapel/all
     *
     * Public endpoint - bisa diakses tanpa auth
     * Digunakan untuk dropdown di frontend
     */
    public function all()
    {
        try {
            $mapels = Mapel::orderBy('nama')->get(['id', 'nama', 'kode']);

            return response()->json([
                'message' => 'Success',
                'mapels' => $mapels->map(function($m) {
                    return [
                        'id' => $m->id,
                        'nama' => $m->nama,
                        'kode' => $m->kode,
                    ];
                }),
                'total' => $mapels->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting all mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create mapel dari array
     * POST /api/admin/mapel/bulk
     * Body: {
     *   "mapels": [
     *     {"nama": "Matematika", "kode": "MTK"},
     *     {"nama": "Bahasa Indonesia", "kode": "BIN"},
     *     {"nama": "IPA", "kode": "IPA"}
     *   ]
     * }
     *
     * Admin only - untuk initial setup mapel
     * Berguna saat pertama kali setup sistem
     */
    public function bulkStore(Request $request)
    {
        $request->validate([
            'mapels' => 'required|array|min:1',
            'mapels.*.nama' => 'required|string|max:255',
            'mapels.*.kode' => 'nullable|string|max:50',
        ]);

        try {
            DB::beginTransaction();

            $results = [
                'success' => [],
                'failed' => [],
            ];

            foreach ($request->mapels as $mapelData) {
                try {
                    // Cek duplikat
                    $exists = Mapel::where('nama', $mapelData['nama'])->exists();

                    if ($exists) {
                        $results['failed'][] = [
                            'nama' => $mapelData['nama'],
                            'reason' => 'Mapel sudah ada'
                        ];
                        continue;
                    }

                    if (!empty($mapelData['kode'])) {
                        $kodeExists = Mapel::where('kode', $mapelData['kode'])->exists();
                        if ($kodeExists) {
                            $results['failed'][] = [
                                'nama' => $mapelData['nama'],
                                'kode' => $mapelData['kode'],
                                'reason' => 'Kode mapel sudah digunakan'
                            ];
                            continue;
                        }
                    }

                    $mapel = Mapel::create([
                        'nama' => $mapelData['nama'],
                        'kode' => $mapelData['kode'] ?? null,
                    ]);

                    $results['success'][] = [
                        'id' => $mapel->id,
                        'nama' => $mapel->nama,
                        'kode' => $mapel->kode,
                    ];
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'nama' => $mapelData['nama'],
                        'reason' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            Log::info('Bulk mapel created', [
                'admin_id' => auth()->guard('api')->id(),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
            ]);

            return response()->json([
                'message' => 'Bulk create selesai',
                'summary' => [
                    'total_requested' => count($request->mapels),
                    'success_count' => count($results['success']),
                    'failed_count' => count($results['failed']),
                ],
                'results' => $results
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk creating mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
