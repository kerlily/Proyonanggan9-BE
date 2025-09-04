<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SiswaAuthController extends Controller
{
    // Login for siswa (nama + kelas_id + password)
    public function login(Request $request)
    {
        $request->validate([
            'nama' => 'required|string',
            'kelas_id' => 'required|integer|exists:kelas,id',
            'password' => 'required|string', // tahun_lahir
        ]);

        $credentials = $request->only('nama', 'kelas_id', 'password');

        if (! $token = Auth::guard('siswa')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token, 'siswa');
    }

    public function me()
    {
        return response()->json(Auth::guard('siswa')->user());
    }

    public function logout()
    {
        Auth::guard('siswa')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    public function refresh()
    {
        $token = Auth::guard('siswa')->refresh();
        return $this->respondWithToken($token, 'siswa');
    }

    protected function respondWithToken($token, $guard)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard($guard)->factory()->getTTL() * 60,
            'user' => Auth::guard($guard)->user(),
        ]);
    }
}
