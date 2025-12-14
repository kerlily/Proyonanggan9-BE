<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class SiswaAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'nama' => 'required|string',
            'kelas_id' => 'required|integer|exists:kelas,id',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('nama', 'kelas_id', 'password');

        if (! $token = Auth::guard('siswa')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token, 'siswa');
    }

    public function me()
    {
        $siswa = Auth::guard('siswa')->user();

        if (!$siswa->relationLoaded('kelas')) {
            $siswa->load('kelas:id,nama,tingkat,section');
        }

        return response()->json([
            'id' => $siswa->id,
            'nama' => $siswa->nama,
            'tahun_lahir' => $siswa->tahun_lahir,
            'kelas_id' => $siswa->kelas_id,
            'is_alumni' => $siswa->is_alumni,
            'kelas' => $siswa->kelas ? [
                'id' => $siswa->kelas->id,
                'nama' => $siswa->kelas->nama,
                'tingkat' => $siswa->kelas->tingkat,
                'section' => $siswa->kelas->section,
            ] : null,
        ]);
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

    /**
     * ✅ CRITICAL: Return MINIMAL data untuk performa maksimal
     * Hindari load relasi yang tidak perlu
     */
    protected function respondWithToken($token, $guard)
    {
        $siswa = Auth::guard($guard)->user();

        // ✅ PENTING: Jangan load relasi di sini untuk performa
        // Frontend bisa call /api/siswa/me untuk dapat data lengkap

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard($guard)->factory()->getTTL() * 60,
            'user' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'tahun_lahir' => $siswa->tahun_lahir,
                'kelas_id' => $siswa->kelas_id,
                'is_alumni' => $siswa->is_alumni,
                // ✅ Kelas data akan di-fetch oleh frontend via /me endpoint
            ],
        ]);
    }
}
