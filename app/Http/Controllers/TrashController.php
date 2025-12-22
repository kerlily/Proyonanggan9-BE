<?php
// app/Http/Controllers/TrashController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Siswa;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Nilai;

class TrashController extends Controller
{
    /**
     * ========================================
     * USERS (Guru/Admin) TRASH
     * ========================================
     */

    /**
     * Get all trashed users dengan pagination
     * GET /api/admin/trash/users?per_page=20
     */
    public function indexUsers(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $search = $request->query('search');

        $query = User::onlyTrashed()->with('guru');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('deleted_at')
                       ->paginate($perPage);

        return response()->json([
            'message' => 'Success',
            'data' => $users->map(function($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'deleted_at' => $u->deleted_at,
                    'deleted_days_ago' => $u->deleted_at->diffInDays(now()),
                    'guru' => $u->guru ? [
                        'id' => $u->guru->id,
                        'nama' => $u->guru->nama,
                        'nip' => $u->guru->nip,
                    ] : null
                ];
            }),
            'meta' => [
                'current_page' => $users->currentPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
            ]
        ]);
    }

    /**
     * Restore single user
     * POST /api/admin/trash/users/{id}/restore
     */
    public function restoreUser($id)
    {
        $user = User::onlyTrashed()->with('guru')->findOrFail($id);

        try {
            DB::beginTransaction();

            $user->restore();

            // Restore guru jika ada
            if ($user->guru) {
                $user->guru->restore();
            }

            DB::commit();

            Log::info('User restored from trash', [
                'admin_id' => auth()->guard('api')->id(),
                'restored_user_id' => $user->id,
                'restored_user_email' => $user->email,
            ]);

            return response()->json([
                'message' => 'User berhasil dipulihkan',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Permanent delete user
     * DELETE /api/admin/trash/users/{id}/force
     */
    public function forceDeleteUser($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);

        try {
            DB::beginTransaction();

            // Hapus guru jika ada
            if ($user->guru) {
                $user->guru->forceDelete();
            }

            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];

            $user->forceDelete();

            DB::commit();

            Log::warning('User permanently deleted', [
                'admin_id' => auth()->guard('api')->id(),
                'deleted_user' => $userData,
            ]);

            return response()->json([
                'message' => 'User berhasil dihapus permanen (tidak bisa dikembalikan)'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================
     * SISWA TRASH
     * ========================================
     */

    /**
     * Get all trashed siswa
     * GET /api/admin/trash/siswa?per_page=20
     */
    public function indexSiswa(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $search = $request->query('search');

        $query = Siswa::onlyTrashed()->with('kelas');

        if ($search) {
            $query->where('nama', 'like', "%{$search}%");
        }

        $siswa = $query->orderByDesc('deleted_at')
                       ->paginate($perPage);

        return response()->json([
            'message' => 'Success',
            'data' => $siswa->map(function($s) {
                return [
                    'id' => $s->id,
                    'nama' => $s->nama,
                    'tahun_lahir' => $s->tahun_lahir,
                    'kelas' => $s->kelas ? [
                        'id' => $s->kelas->id,
                        'nama' => $s->kelas->nama,
                    ] : null,
                    'deleted_at' => $s->deleted_at,
                    'deleted_days_ago' => $s->deleted_at->diffInDays(now()),
                ];
            }),
            'meta' => [
                'current_page' => $siswa->currentPage(),
                'total' => $siswa->total(),
                'per_page' => $siswa->perPage(),
            ]
        ]);
    }

    /**
     * Restore siswa
     * POST /api/admin/trash/siswa/{id}/restore
     */
    public function restoreSiswa($id)
    {
        $siswa = Siswa::onlyTrashed()->findOrFail($id);

        try {
            $siswa->restore();

            Log::info('Siswa restored from trash', [
                'admin_id' => auth()->guard('api')->id(),
                'restored_siswa_id' => $siswa->id,
                'restored_siswa_nama' => $siswa->nama,
            ]);

            return response()->json([
                'message' => 'Siswa berhasil dipulihkan',
                'siswa' => $siswa
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete siswa
     * DELETE /api/admin/trash/siswa/{id}/force
     *
     * PERHATIAN: Ini akan menghapus SEMUA data nilai siswa juga!
     */
    public function forceDeleteSiswa($id)
    {
        $siswa = Siswa::onlyTrashed()->findOrFail($id);

        try {
            DB::beginTransaction();

            // Count data yang akan terhapus
            $nilaiCount = Nilai::where('siswa_id', $siswa->id)->count();
            $riwayatCount = DB::table('riwayat_kelas')
                ->where('siswa_id', $siswa->id)
                ->count();

            $siswaData = [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'tahun_lahir' => $siswa->tahun_lahir,
            ];

            // Hapus semua data terkait
            Nilai::where('siswa_id', $siswa->id)->forceDelete();
            DB::table('riwayat_kelas')->where('siswa_id', $siswa->id)->delete();

            $siswa->forceDelete();

            DB::commit();

            Log::warning('Siswa permanently deleted with all related data', [
                'admin_id' => auth()->guard('api')->id(),
                'deleted_siswa' => $siswaData,
                'deleted_nilai_count' => $nilaiCount,
                'deleted_riwayat_count' => $riwayatCount,
            ]);

            return response()->json([
                'message' => 'Siswa berhasil dihapus permanen beserta ' . $nilaiCount . ' data nilai',
                'deleted' => [
                    'nilai' => $nilaiCount,
                    'riwayat_kelas' => $riwayatCount,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================
     * KELAS TRASH
     * ========================================
     */

    /**
     * Get all trashed kelas
     * GET /api/admin/trash/kelas
     */
    public function indexKelas(Request $request)
    {
        $kelas = Kelas::onlyTrashed()
            ->withCount('siswa')
            ->orderByDesc('deleted_at')
            ->get();

        return response()->json([
            'message' => 'Success',
            'data' => $kelas->map(function($k) {
                return [
                    'id' => $k->id,
                    'nama' => $k->nama,
                    'tingkat' => $k->tingkat,
                    'section' => $k->section,
                    'siswa_count' => $k->siswa_count,
                    'deleted_at' => $k->deleted_at,
                    'deleted_days_ago' => $k->deleted_at->diffInDays(now()),
                ];
            })
        ]);
    }

    /**
     * Restore kelas
     * POST /api/admin/trash/kelas/{id}/restore
     */
    public function restoreKelas($id)
    {
        $kelas = Kelas::onlyTrashed()->findOrFail($id);

        try {
            $kelas->restore();

            Log::info('Kelas restored from trash', [
                'admin_id' => auth()->guard('api')->id(),
                'restored_kelas_id' => $kelas->id,
                'restored_kelas_nama' => $kelas->nama,
            ]);

            return response()->json([
                'message' => 'Kelas berhasil dipulihkan',
                'kelas' => $kelas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete kelas
     * DELETE /api/admin/trash/kelas/{id}/force
     */
    public function forceDeleteKelas($id)
    {
        $kelas = Kelas::onlyTrashed()->findOrFail($id);

        try {
            $kelasData = [
                'id' => $kelas->id,
                'nama' => $kelas->nama,
            ];

            $kelas->forceDelete();

            Log::warning('Kelas permanently deleted', [
                'admin_id' => auth()->guard('api')->id(),
                'deleted_kelas' => $kelasData,
            ]);

            return response()->json([
                'message' => 'Kelas berhasil dihapus permanen'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================
     * DASHBOARD TRASH
     * ========================================
     */

    /**
     * Get trash statistics
     * GET /api/admin/trash/stats
     */
    public function stats()
    {
        $stats = [
            'users' => [
                'count' => User::onlyTrashed()->count(),
                'oldest' => User::onlyTrashed()->orderBy('deleted_at')->first(),
            ],
            'siswa' => [
                'count' => Siswa::onlyTrashed()->count(),
                'oldest' => Siswa::onlyTrashed()->orderBy('deleted_at')->first(),
            ],
            'guru' => [
                'count' => Guru::onlyTrashed()->count(),
                'oldest' => Guru::onlyTrashed()->orderBy('deleted_at')->first(),
            ],
            'kelas' => [
                'count' => Kelas::onlyTrashed()->count(),
                'oldest' => Kelas::onlyTrashed()->orderBy('deleted_at')->first(),
            ],
            'nilai' => [
                'count' => Nilai::onlyTrashed()->count(),
                'oldest' => Nilai::onlyTrashed()->orderBy('deleted_at')->first(),
            ],
        ];

        return response()->json([
            'message' => 'Success',
            'stats' => array_map(function($item) {
                return [
                    'count' => $item['count'],
                    'oldest_deleted_at' => $item['oldest'] ? $item['oldest']->deleted_at : null,
                    'oldest_days_ago' => $item['oldest']
                        ? $item['oldest']->deleted_at->diffInDays(now())
                        : null,
                ];
            }, $stats)
        ]);
    }

    /**
     * Bulk restore
     * POST /api/admin/trash/bulk-restore
     * Body: { "model": "siswa", "ids": [1,2,3] }
     */
    public function bulkRestore(Request $request)
    {
        $validated = $request->validate([
            'model' => 'required|in:users,siswa,guru,kelas',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $model = match($validated['model']) {
            'users' => User::class,
            'siswa' => Siswa::class,
            'guru' => Guru::class,
            'kelas' => Kelas::class,
        };

        try {
            $restored = $model::onlyTrashed()
                ->whereIn('id', $validated['ids'])
                ->restore();

            Log::info('Bulk restore completed', [
                'admin_id' => auth()->guard('api')->id(),
                'model' => $validated['model'],
                'restored_count' => $restored,
            ]);

            return response()->json([
                'message' => "{$restored} record(s) berhasil dipulihkan",
                'restored_count' => $restored
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
