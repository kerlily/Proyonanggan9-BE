<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class SiswaAuthController extends Controller
{
    /**
     * Login siswa
     * Supports both JSON response AND cookie storage
     */
    public function login(Request $request)
    {
        $request->validate([
            'nama' => 'required|string',
            'kelas_id' => 'required|integer|exists:kelas,id',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('nama', 'kelas_id', 'password');

        if (!$token = Auth::guard('siswa')->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $useCookie = $request->input('use_cookie', false) ||
                     $request->header('X-Use-Cookie', false);

        return $this->respondWithToken($token, 'siswa', $useCookie);
    }

    /**
     * Get authenticated siswa data
     * Supports both Authorization header AND cookie
     */
    public function me(Request $request)
    {
        try {
            // Try header first, then cookie
            $siswa = Auth::guard('siswa')->user();

            if (!$siswa && $request->hasCookie('siswa_token')) {
                $token = $request->cookie('siswa_token');
                JWTAuth::setToken($token);
                $siswa = JWTAuth::authenticate();
            }

            if (!$siswa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            if (!$siswa->relationLoaded('kelas')) {
                $siswa->load('kelas:id,nama,tingkat,section');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $siswa->id,
                    'nama' => $siswa->nama,
                    'nisn' => $siswa->nisn,
                    'tahun_lahir' => $siswa->tahun_lahir,
                    'kelas_id' => $siswa->kelas_id,
                    'is_alumni' => $siswa->is_alumni,
                    'kelas' => $siswa->kelas ? [
                        'id' => $siswa->kelas->id,
                        'nama' => $siswa->kelas->nama,
                        'tingkat' => $siswa->kelas->tingkat,
                        'section' => $siswa->kelas->section,
                    ] : null,
                ]
            ]);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired',
                'error_code' => 'TOKEN_EXPIRED'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid',
                'error_code' => 'TOKEN_INVALID'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is absent',
                'error_code' => 'TOKEN_ABSENT'
            ], 401);
        }
    }

    /**
     * Logout siswa - clear cookie
     */
    public function logout(Request $request)
    {
        try {
            Auth::guard('siswa')->logout();

            // Clear cookie
            $cookie = Cookie::forget('siswa_token');

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out'
            ])->cookie($cookie);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout, please try again'
            ], 500);
        }
    }

    /**
     * Refresh siswa token
     */
    public function refresh(Request $request)
    {
        try {
            $token = Auth::guard('siswa')->refresh();

            $useCookie = $request->input('use_cookie', false) ||
                         $request->hasCookie('siswa_token');

            return $this->respondWithToken($token, 'siswa', $useCookie);
        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token has expired, please login again',
                'error_code' => 'REFRESH_TOKEN_EXPIRED'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not refresh token',
                'error_code' => 'REFRESH_FAILED'
            ], 401);
        }
    }

    /**
     * Respond with token (with optional cookie)
     */
    protected function respondWithToken($token, $guard, $useCookie = false)
    {
        $siswa = Auth::guard($guard)->user();

        $ttl = Auth::guard($guard)->factory()->getTTL();
        $expiresIn = $ttl * 60;

        $response = response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'expires_in_minutes' => $ttl,
            'expires_in_days' => round($ttl / 1440, 1),
            'user' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'tahun_lahir' => $siswa->tahun_lahir,
                'kelas_id' => $siswa->kelas_id,
                'is_alumni' => $siswa->is_alumni,
            ],
            'stored_in' => $useCookie ? 'cookie' : 'json',
        ]);

        // Attach cookie if requested
        if ($useCookie) {
            $cookie = Cookie::make(
                'siswa_token',
                $token,
                $ttl,
                '/',
                null,
                true,  // secure (HTTPS only, set false for local dev)
                true,  // httpOnly
                false,
                'Lax'
            );

            return $response->cookie($cookie);
        }

        return $response;
    }

    /**
     * Check siswa token validity
     */
    public function checkToken(Request $request)
    {
        try {
            $siswa = Auth::guard('siswa')->user();

            if (!$siswa && $request->hasCookie('siswa_token')) {
                $token = $request->cookie('siswa_token');
                JWTAuth::setToken($token);
                $siswa = JWTAuth::authenticate();
            }

            if (!$siswa) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Token is invalid'
                ], 401);
            }

            $payload = Auth::guard('siswa')->payload();
            $expiresAt = $payload->get('exp');
            $now = time();
            $remainingSeconds = $expiresAt - $now;

            return response()->json([
                'success' => true,
                'valid' => true,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'remaining_seconds' => $remainingSeconds,
                'remaining_days' => round($remainingSeconds / 86400, 1),
                'siswa_id' => $siswa->id,
                'siswa_nama' => $siswa->nama,
                'stored_in' => $request->hasCookie('siswa_token') ? 'cookie' : 'header',
            ]);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Token has expired',
                'error_code' => 'TOKEN_EXPIRED'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Token is invalid',
                'error_code' => 'TOKEN_INVALID'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Token is absent',
                'error_code' => 'TOKEN_ABSENT'
            ], 401);
        }
    }
}
