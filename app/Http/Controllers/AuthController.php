<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    // Login for guru/admin (users)
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token, 'api');
    }

    /**
     * Get current authenticated user with complete guru data
     * FIXED: Load guru relation dengan semua field termasuk photo_url
     */
    public function me()
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Load guru relation jika user adalah guru
        if ($user->role === 'guru') {
            $user->load('guru');

            // FIXED: Build photo_url PERSIS seperti PublicGuruController
            if ($user->guru && $user->guru->photo) {
                $user->guru->photo_url = url('storage/'.$user->guru->photo);
            }

            // PENTING: Pastikan field guru tampil di response
            // Tambahkan data guru ke level user juga untuk backward compatibility
            if ($user->guru) {
                $user->nama = $user->guru->nama;
                $user->nip = $user->guru->nip;
                $user->no_hp = $user->guru->no_hp;
                $user->photo = $user->guru->photo;
                $user->photo_url = $user->guru->photo_url ?? null;
            }
        }

        return response()->json($user);
    }

    public function logout()
    {
        Auth::guard('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    public function refresh()
    {
        $token = Auth::guard('api')->refresh();
        return $this->respondWithToken($token, 'api');
    }

    /**
     * Respond with token and complete user data
     * FIXED: Include guru data dengan photo_url
     */
    protected function respondWithToken($token, $guard)
    {
        $user = Auth::guard($guard)->user();

        // Load guru relation untuk role guru
        if ($user && $user->role === 'guru') {
            $user->load('guru');

            // FIXED: Build photo_url dengan url() helper
            if ($user->guru && $user->guru->photo) {
                $user->guru->photo_url = url('storage/' . $user->guru->photo);
            }

            // Tambahkan data guru ke level user untuk compatibility
            if ($user->guru) {
                $user->nama = $user->guru->nama;
                $user->nip = $user->guru->nip;
                $user->no_hp = $user->guru->no_hp;
                $user->photo = $user->guru->photo;
                $user->photo_url = $user->guru->photo_url ?? null;
            }
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard($guard)->factory()->getTTL() * 60,
            'user' => $user,
        ]);
    }
}
