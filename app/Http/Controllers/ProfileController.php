<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;

class ProfileController extends Controller
{
    /**
     * Update profile for Guru/Admin (auth:api)
     */
    public function updateUserProfile(Request $request)
    {
        $user = auth()->guard('api')->user();

        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'no_hp' => 'sometimes|string|max:30',
            'nip'   => 'sometimes|string|max:50',
            'photo' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // update user table
        if ($request->filled('name')) $user->name = $request->name;
        if ($request->filled('email')) $user->email = $request->email;
        $user->save();

        // jika user adalah guru, update juga tabel guru
        if ($user->role === 'guru') {
            $guru = $user->guru;
            if ($guru) {
                if ($request->filled('name')) $guru->nama = $request->name;
                if ($request->filled('no_hp')) $guru->no_hp = $request->no_hp;
                if ($request->filled('nip')) $guru->nip = $request->nip;

                if ($request->hasFile('photo')) {
                    // hapus foto lama
                    if ($guru->photo && Storage::disk('public')->exists($guru->photo)) {
                        Storage::disk('public')->delete($guru->photo);
                    }
                    $guru->photo = $request->file('photo')->store('guru_photos', 'public');
                }
                $guru->save();
            }
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->load('guru'),
        ]);
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

        if (! Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'Password lama salah'], 422);
        }

        $user->password = $request->new_password; // mutator di model User hash otomatis
        $user->save();

        return response()->json(['message' => 'Password updated successfully']);
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

        if (! Hash::check($request->old_password, $siswa->password)) {
            return response()->json(['message' => 'Password lama salah'], 422);
        }

        $siswa->password = $request->new_password; // mutator di model Siswa hash otomatis
        $siswa->save();

        return response()->json(['message' => 'Password updated successfully']);
    }
}
