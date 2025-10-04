<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Siswa;

class AdminResetPasswordController extends Controller
{
    /**
     * Reset password guru/admin (User)
     */
    public function resetUserPassword(Request $request, $user_id)
    {
        $request->validate([
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::findOrFail($user_id);
        $user->password = $request->new_password; // mutator di model otomatis hash
        $user->save();

        return response()->json([
            'message' => "Password user {$user->name} berhasil direset"
        ]);
    }

    /**
     * Reset password siswa
     */
    public function resetSiswaPassword(Request $request, $id)
    {
        $request->validate([
            'new_password' => 'required|string|min:4|confirmed',
        ]);

        $siswa = Siswa::findOrFail($id);
        $siswa->password = $request->new_password; // mutator di model otomatis hash
        $siswa->save();

        return response()->json([
            'message' => "Password siswa {$siswa->nama} berhasil direset"
        ]);
    }
}
