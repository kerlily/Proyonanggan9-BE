<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kelas;
use App\Models\Mapel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KelasMapelController extends Controller
{
    /**
     * Get daftar mapel untuk kelas tertentu
     * GET /api/kelas/{kelas_id}/mapel
     *
     * Bisa diakses oleh semua user (untuk lihat mapel apa saja yang ada di kelas)
     * Digunakan untuk: frontend dropdown, template download, dll
     */
    public function index($kelas_id)
    {
        try {
            $kelas = Kelas::with('mapels')->findOrFail($kelas_id);

            return response()->json([
                'message' => 'Success',
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $kelas->nama,
                    'tingkat' => $kelas->tingkat,
                    'section' => $kelas->section,
                ],
                'mapels' => $kelas->mapels->map(function($m) {
                    return [
                        'id' => $m->id,
                        'nama' => $m->nama,
                        'kode' => $m->kode,
                        'assigned_at' => $m->pivot->created_at,
                    ];
                }),
                'total_mapel' => $kelas->mapels->count()
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting kelas mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign multiple mapel ke kelas sekaligus (replace existing)
     * POST /api/admin/kelas/{kelas_id}/mapel
     * Body: { "mapel_ids": [1, 2, 3, 4] }
     *
     * Admin only - untuk set ulang semua mapel kelas
     * Gunakan ini saat pertama kali setup kelas atau mau ganti total semua mapel
     */
    public function assign(Request $request, $kelas_id)
    {
        $request->validate([
            'mapel_ids' => 'required|array|min:1',
            'mapel_ids.*' => 'integer|exists:mapel,id'
        ]);

        try {
            $kelas = Kelas::findOrFail($kelas_id);

            DB::beginTransaction();

            // Sync akan replace semua relasi dengan yang baru
            // Gunakan sync() untuk replace all
            $kelas->mapels()->sync($request->mapel_ids);

            DB::commit();

            Log::info('Mapel assigned to kelas', [
                'admin_id' => auth()->guard('api')->id(),
                'kelas_id' => $kelas_id,
                'kelas_nama' => $kelas->nama,
                'mapel_count' => count($request->mapel_ids),
            ]);

            return response()->json([
                'message' => "Berhasil assign " . count($request->mapel_ids) . " mapel ke kelas {$kelas->nama}",
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $kelas->nama,
                    'tingkat' => $kelas->tingkat,
                ],
                'mapels' => $kelas->fresh()->load('mapels')->mapels
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error assigning mapel to kelas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tambah satu mapel ke kelas (tanpa hapus yang lain)
     * POST /api/admin/kelas/{kelas_id}/mapel/{mapel_id}
     *
     * Admin only - untuk tambah mapel baru tanpa hapus yang existing
     * Gunakan ini untuk menambah 1 mapel saja
     */
    public function attach($kelas_id, $mapel_id)
    {
        try {
            $kelas = Kelas::findOrFail($kelas_id);
            $mapel = Mapel::findOrFail($mapel_id);

            // Cek apakah sudah ada
            if ($kelas->mapels()->where('mapel_id', $mapel_id)->exists()) {
                return response()->json([
                    'message' => "Mapel '{$mapel->nama}' sudah ada di kelas {$kelas->nama}"
                ], 422);
            }

            DB::beginTransaction();

            // Attach hanya menambah, tidak replace
            $kelas->mapels()->attach($mapel_id);

            DB::commit();

            Log::info('Mapel attached to kelas', [
                'admin_id' => auth()->guard('api')->id(),
                'kelas_id' => $kelas_id,
                'mapel_id' => $mapel_id,
            ]);

            return response()->json([
                'message' => "Mapel '{$mapel->nama}' berhasil ditambahkan ke kelas {$kelas->nama}",
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $kelas->nama,
                ],
                'mapel' => [
                    'id' => $mapel->id,
                    'nama' => $mapel->nama,
                    'kode' => $mapel->kode,
                ],
                'total_mapel_now' => $kelas->mapels()->count()
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas atau mapel tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error attaching mapel to kelas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hapus satu mapel dari kelas
     * DELETE /api/admin/kelas/{kelas_id}/mapel/{mapel_id}
     *
     * Admin only - untuk hapus mapel dari kelas
     * HATI-HATI: Jika ada nilai siswa yang sudah pakai mapel ini, akan error
     */
    public function detach($kelas_id, $mapel_id)
    {
        try {
            $kelas = Kelas::findOrFail($kelas_id);
            $mapel = Mapel::findOrFail($mapel_id);

            if (!$kelas->mapels()->where('mapel_id', $mapel_id)->exists()) {
                return response()->json([
                    'message' => "Mapel '{$mapel->nama}' tidak ada di kelas {$kelas->nama}"
                ], 404);
            }

            // Cek apakah ada nilai yang menggunakan mapel ini di kelas ini
            $nilaiCount = DB::table('nilai')
                ->join('siswa', 'nilai.siswa_id', '=', 'siswa.id')
                ->where('siswa.kelas_id', $kelas_id)
                ->where('nilai.mapel_id', $mapel_id)
                ->count();

            if ($nilaiCount > 0) {
                return response()->json([
                    'message' => "Tidak bisa hapus mapel '{$mapel->nama}' karena sudah ada {$nilaiCount} data nilai siswa yang menggunakan mapel ini di kelas {$kelas->nama}.",
                    'suggestion' => 'Hapus data nilai terlebih dahulu, atau biarkan mapel tetap di kelas.'
                ], 422);
            }

            DB::beginTransaction();

            $kelas->mapels()->detach($mapel_id);

            DB::commit();

            Log::info('Mapel detached from kelas', [
                'admin_id' => auth()->guard('api')->id(),
                'kelas_id' => $kelas_id,
                'mapel_id' => $mapel_id,
            ]);

            return response()->json([
                'message' => "Mapel '{$mapel->nama}' berhasil dihapus dari kelas {$kelas->nama}",
                'total_mapel_now' => $kelas->mapels()->count()
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas atau mapel tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error detaching mapel from kelas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get semua mapel yang available (belum di-assign ke kelas ini)
     * GET /api/admin/kelas/{kelas_id}/mapel/available
     *
     * Admin only - untuk tau mapel mana yang bisa ditambahkan
     * Digunakan untuk: dropdown "tambah mapel" di frontend
     */
    public function available($kelas_id)
    {
        try {
            $kelas = Kelas::findOrFail($kelas_id);

            // Ambil ID mapel yang sudah di-assign
            $assignedMapelIds = $kelas->mapels()->pluck('mapel.id');

            // Ambil mapel yang belum di-assign
            $availableMapels = Mapel::whereNotIn('id', $assignedMapelIds)
                ->orderBy('nama')
                ->get();

            return response()->json([
                'message' => 'Success',
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $kelas->nama,
                    'tingkat' => $kelas->tingkat,
                    'assigned_mapel_count' => $assignedMapelIds->count()
                ],
                'available_mapels' => $availableMapels->map(function($m) {
                    return [
                        'id' => $m->id,
                        'nama' => $m->nama,
                        'kode' => $m->kode,
                    ];
                }),
                'total_available' => $availableMapels->count()
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting available mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copy mapel dari kelas lain
     * POST /api/admin/kelas/{kelas_id}/mapel/copy-from/{source_kelas_id}
     *
     * Admin only - untuk copy setup mapel dari kelas lain
     * Use case: Setup kelas 1B dengan copy dari kelas 1A
     */
    public function copyFrom($kelas_id, $source_kelas_id)
    {
        try {
            $targetKelas = Kelas::findOrFail($kelas_id);
            $sourceKelas = Kelas::with('mapels')->findOrFail($source_kelas_id);

            if ($sourceKelas->mapels->isEmpty()) {
                return response()->json([
                    'message' => "Kelas {$sourceKelas->nama} belum punya mapel yang di-assign. Assign mapel terlebih dahulu."
                ], 422);
            }

            DB::beginTransaction();

            // Ambil semua mapel_id dari source kelas
            $mapelIds = $sourceKelas->mapels->pluck('id')->toArray();

            // Sync ke target kelas (akan replace yang existing)
            $targetKelas->mapels()->sync($mapelIds);

            DB::commit();

            Log::info('Mapel copied between kelas', [
                'admin_id' => auth()->guard('api')->id(),
                'source_kelas_id' => $source_kelas_id,
                'target_kelas_id' => $kelas_id,
                'mapel_count' => count($mapelIds),
            ]);

            return response()->json([
                'message' => "Berhasil copy {$sourceKelas->mapels->count()} mapel dari kelas {$sourceKelas->nama} ke {$targetKelas->nama}",
                'source_kelas' => [
                    'id' => $sourceKelas->id,
                    'nama' => $sourceKelas->nama,
                    'tingkat' => $sourceKelas->tingkat,
                ],
                'target_kelas' => [
                    'id' => $targetKelas->id,
                    'nama' => $targetKelas->nama,
                    'tingkat' => $targetKelas->tingkat,
                ],
                'mapels' => $targetKelas->fresh()->load('mapels')->mapels->map(function($m) {
                    return [
                        'id' => $m->id,
                        'nama' => $m->nama,
                        'kode' => $m->kode,
                    ];
                })
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Kelas source atau target tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error copying mapel between kelas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistik mapel per kelas (untuk admin dashboard)
     * GET /api/admin/mapel/statistics
     *
     * Admin only - untuk dashboard/monitoring
     * Menampilkan kelas mana yang sudah/belum assign mapel
     */
    public function statistics()
    {
        try {
            $stats = DB::table('kelas')
                ->leftJoin('kelas_mapel', 'kelas.id', '=', 'kelas_mapel.kelas_id')
                ->select(
                    'kelas.id',
                    'kelas.nama',
                    'kelas.tingkat',
                    'kelas.section',
                    DB::raw('COUNT(kelas_mapel.mapel_id) as mapel_count')
                )
                ->groupBy('kelas.id', 'kelas.nama', 'kelas.tingkat', 'kelas.section')
                ->orderBy('kelas.tingkat')
                ->orderBy('kelas.section')
                ->get();

            $kelasWithMapel = $stats->where('mapel_count', '>', 0);
            $kelasWithoutMapel = $stats->where('mapel_count', 0);

            return response()->json([
                'message' => 'Success',
                'statistics' => $stats->map(function($s) {
                    return [
                        'id' => $s->id,
                        'nama' => $s->nama,
                        'tingkat' => $s->tingkat,
                        'section' => $s->section,
                        'mapel_count' => (int) $s->mapel_count,
                        'status' => $s->mapel_count > 0 ? 'assigned' : 'not_assigned'
                    ];
                }),
                'summary' => [
                    'total_kelas' => $stats->count(),
                    'kelas_with_mapel' => $kelasWithMapel->count(),
                    'kelas_without_mapel' => $kelasWithoutMapel->count(),
                    'percentage_assigned' => $stats->count() > 0
                        ? round(($kelasWithMapel->count() / $stats->count()) * 100, 1)
                        : 0
                ],
                'kelas_need_attention' => $kelasWithoutMapel->map(function($k) {
                    return [
                        'id' => $k->id,
                        'nama' => $k->nama,
                        'tingkat' => $k->tingkat,
                    ];
                })->values()
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting mapel statistics: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk assign mapel ke multiple kelas sekaligus
     * POST /api/admin/mapel/bulk-assign
     * Body: {
     *   "kelas_ids": [1, 2, 3],
     *   "mapel_ids": [1, 2, 3, 4, 5]
     * }
     *
     * Admin only - untuk assign mapel yang sama ke banyak kelas sekaligus
     * Use case: Setup semua kelas tingkat 1 dengan mapel yang sama
     */
    public function bulkAssign(Request $request)
    {
        $request->validate([
            'kelas_ids' => 'required|array|min:1',
            'kelas_ids.*' => 'integer|exists:kelas,id',
            'mapel_ids' => 'required|array|min:1',
            'mapel_ids.*' => 'integer|exists:mapel,id'
        ]);

        try {
            DB::beginTransaction();

            $results = [];

            foreach ($request->kelas_ids as $kelasId) {
                $kelas = Kelas::find($kelasId);
                if ($kelas) {
                    $kelas->mapels()->sync($request->mapel_ids);
                    $results[] = [
                        'kelas_id' => $kelasId,
                        'kelas_nama' => $kelas->nama,
                        'status' => 'success',
                        'mapel_count' => count($request->mapel_ids)
                    ];
                } else {
                    $results[] = [
                        'kelas_id' => $kelasId,
                        'status' => 'not_found'
                    ];
                }
            }

            DB::commit();

            Log::info('Bulk assign mapel to kelas', [
                'admin_id' => auth()->guard('api')->id(),
                'kelas_count' => count($request->kelas_ids),
                'mapel_count' => count($request->mapel_ids),
            ]);

            return response()->json([
                'message' => 'Bulk assign selesai',
                'summary' => [
                    'total_kelas' => count($request->kelas_ids),
                    'total_mapel' => count($request->mapel_ids),
                    'success_count' => collect($results)->where('status', 'success')->count(),
                ],
                'results' => $results
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error bulk assigning mapel: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
