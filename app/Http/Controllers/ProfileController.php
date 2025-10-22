<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;

class ProfileController extends Controller
{
    /**
     * Update profile for Guru/Admin (auth:api)
     * FIXED: Return complete data dengan photo_url
     */
    public function updateUserProfile(Request $request)
    {
        $user = auth()->guard('api')->user();

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'no_hp' => 'sometimes|string|max:30',
            'nip'   => 'sometimes|string|max:50',
            'photo' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        try {
            // update user table
            if ($request->filled('name')) {
                $user->name = $request->name;
            }
            if ($request->filled('email')) {
                $user->email = $request->email;
            }
            $user->save();

            $photoUrl = null;

            // jika user adalah guru, update juga tabel guru
            if ($user->role === 'guru') {
                $guru = $user->guru;
                if ($guru) {
                    if ($request->filled('name')) {
                        $guru->nama = $request->name;
                    }
                    if ($request->filled('no_hp')) {
                        $guru->no_hp = $request->no_hp;
                    }
                    if ($request->filled('nip')) {
                        $guru->nip = $request->nip;
                    }

                    if ($request->hasFile('photo')) {
                        // hapus foto lama
                        if ($guru->photo && Storage::disk('public')->exists($guru->photo)) {
                            Storage::disk('public')->delete($guru->photo);
                        }
                        $guru->photo = $request->file('photo')->store('guru_photos', 'public');
                    }

                    $guru->save();

                    // FIXED: Build photo URL PERSIS seperti PublicGuruController
                    if ($guru->photo) {
                        $photoUrl = url('storage/'.$guru->photo);
                    }

                    // Reload guru dengan relasi
                    $guru = $guru->fresh();

                    // IMPORTANT: Return complete response dengan semua data
                    return response()->json([
                        'message' => 'Profile updated successfully',
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'role' => $user->role,
                            // Data guru di level user (backward compatibility)
                            'nama' => $guru->nama,
                            'nip' => $guru->nip,
                            'no_hp' => $guru->no_hp,
                            'photo' => $guru->photo,
                            'photo_url' => $photoUrl,
                        ],
                        'guru' => [
                            'id' => $guru->id,
                            'user_id' => $guru->user_id,
                            'nama' => $guru->nama,
                            'nip' => $guru->nip,
                            'no_hp' => $guru->no_hp,
                            'photo' => $guru->photo,
                            'photo_url' => $photoUrl,
                            'created_at' => $guru->created_at,
                            'updated_at' => $guru->updated_at,
                        ],
                        'photo_url' => $photoUrl,
                    ]);
                }
            }

            // Jika admin (bukan guru)
            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            Log::error('Profile update error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password for Guru/Admin (auth:api)
     */
    public function changeUserPassword(Request $request)
    {
        $user = auth()->guard('api')->user();

        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            // Verify old password
            if (!Hash::check($request->old_password, $user->password)) {
                return response()->json([
                    'message' => 'Password lama salah'
                ], 422);
            }

            // Update password (User model has mutator to hash automatically)
            $user->password = $request->new_password;
            $user->save();

            Log::info('Password changed', ['user_id' => $user->id]);

            return response()->json([
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Password change error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to change password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password for Siswa (auth:siswa)
     */
    public function changeSiswaPassword(Request $request)
    {
        $siswa = auth()->guard('siswa')->user();

        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:4|confirmed',
        ]);

        try {
            if (!Hash::check($request->old_password, $siswa->password)) {
                return response()->json([
                    'message' => 'Password lama salah'
                ], 422);
            }

            $siswa->password = $request->new_password;
            $siswa->save();

            Log::info('Siswa password changed', ['siswa_id' => $siswa->id]);

            return response()->json([
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Siswa password change error', [
                'siswa_id' => $siswa->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to change password: ' . $e->getMessage()
            ], 500);
        }
    }
}
