<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;
use Illuminate\Support\Facades\Log;

class AdminResetPasswordController extends Controller
{
    /**
     * Reset password guru/admin (User)
     * FIXED: Parameter adalah guru_id dari table guru, bukan user_id
     *
     * Route: POST /api/admin/guru/{guru_id}/reset-password
     */
    public function resetUserPassword(Request $request, $guru_id)
    {
        $request->validate([
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            // FIXED: Cari guru dulu berdasarkan guru_id
            $guru = Guru::with('user')->findOrFail($guru_id);

            if (!$guru->user) {
                return response()->json([
                    'message' => 'User tidak ditemukan untuk guru ini'
                ], 404);
            }

            $user = $guru->user;

            // Log untuk debugging
            Log::info('Admin reset guru password', [
                'admin_id' => auth()->guard('api')->id(),
                'guru_id' => $guru_id,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]);

            // Update password
            $user->password = $request->new_password; // mutator di model otomatis hash
            $user->save();

            return response()->json([
                'message' => "Password user {$user->name} berhasil direset",
                'guru' => [
                    'id' => $guru->id,
                    'nama' => $guru->nama,
                    'nip' => $guru->nip,
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Guru tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error resetting guru password', [
                'admin_id' => auth()->guard('api')->id(),
                'guru_id' => $guru_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat mereset password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset password siswa
     * Parameter tetap siswa_id karena tidak ada table terpisah
     *
     * Route: POST /api/admin/siswa/{siswa_id}/reset-password
     */
    public function resetSiswaPassword(Request $request, $siswa_id)
    {
        $request->validate([
            'new_password' => 'required|string|min:4|confirmed',
        ]);

        try {
            $siswa = Siswa::findOrFail($siswa_id);

            Log::info('Admin reset siswa password', [
                'admin_id' => auth()->guard('api')->id(),
                'siswa_id' => $siswa_id,
                'siswa_name' => $siswa->nama,
            ]);

            $siswa->password = $request->new_password; // mutator di model otomatis hash
            $siswa->save();

            return response()->json([
                'message' => "Password siswa {$siswa->nama} berhasil direset",
                'siswa' => [
                    'id' => $siswa->id,
                    'nama' => $siswa->nama,
                    'tahun_lahir' => $siswa->tahun_lahir,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Siswa tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error resetting siswa password', [
                'admin_id' => auth()->guard('api')->id(),
                'siswa_id' => $siswa_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat mereset password siswa: ' . $e->getMessage()
            ], 500);
        }
    }
}
