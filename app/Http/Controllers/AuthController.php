<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class AuthController extends Controller
{
    /**
     * Login for guru/admin (users)
     * Returns access token with 14 days validity
     * Supports both JSON response AND cookie storage
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user wants cookie-based auth (from request header or body)
        $useCookie = $request->input('use_cookie', false) ||
                     $request->header('X-Use-Cookie', false);

        return $this->respondWithToken($token, 'api', $useCookie);
    }

    /**
     * Get current authenticated user
     * Supports both Authorization header AND cookie
     */
    public function me(Request $request)
    {
        try {
            // Try to get token from Authorization header first
            $user = Auth::guard('api')->user();

            // If no user from header, try cookie
            if (!$user && $request->hasCookie('access_token')) {
                $token = $request->cookie('access_token');
                JWTAuth::setToken($token);
                $user = JWTAuth::authenticate();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Load guru relation jika user adalah guru
            if ($user->role === 'guru') {
                $user->load('guru');

                if ($user->guru && $user->guru->photo) {
                    $user->guru->photo_url = url('storage/'.$user->guru->photo);
                }

                if ($user->guru) {
                    $user->nama = $user->guru->nama;
                    $user->nip = $user->guru->nip;
                    $user->no_hp = $user->guru->no_hp;
                    $user->photo = $user->guru->photo;
                    $user->photo_url = $user->guru->photo_url ?? null;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $user
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
     * Logout - Invalidate token and clear cookie
     */
    public function logout(Request $request)
    {
        try {
            Auth::guard('api')->logout();

            // Clear cookie if exists
            $cookie = Cookie::forget('access_token');

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
     * Refresh token
     * Returns new access token with extended validity
     */
    public function refresh(Request $request)
    {
        try {
            $token = Auth::guard('api')->refresh();

            $useCookie = $request->input('use_cookie', false) ||
                         $request->hasCookie('access_token');

            return $this->respondWithToken($token, 'api', $useCookie);
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
     * Respond with token and complete user data
     * Supports cookie storage if requested
     */
    protected function respondWithToken($token, $guard, $useCookie = false)
    {
        $user = Auth::guard($guard)->user();

        // Load guru relation untuk role guru
        if ($user && $user->role === 'guru') {
            $user->load('guru');

            if ($user->guru && $user->guru->photo) {
                $user->guru->photo_url = url('storage/' . $user->guru->photo);
            }

            if ($user->guru) {
                $user->nama = $user->guru->nama;
                $user->nip = $user->guru->nip;
                $user->no_hp = $user->guru->no_hp;
                $user->photo = $user->guru->photo;
                $user->photo_url = $user->guru->photo_url ?? null;
            }
        }

        $ttl = Auth::guard($guard)->factory()->getTTL();
        $expiresIn = $ttl * 60; // Convert to seconds

        $response = response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'expires_in_minutes' => $ttl,
            'expires_in_days' => round($ttl / 1440, 1),
            'user' => $user,
            'stored_in' => $useCookie ? 'cookie' : 'json', // Info untuk frontend
        ]);

        // If cookie requested, attach HttpOnly cookie
        if ($useCookie) {
            $cookie = Cookie::make(
                'access_token',           // name
                $token,                    // value
                $ttl,                      // minutes (14 days = 20160 minutes)
                '/',                       // path
                null,                      // domain (null = current domain)
                true,                      // secure (true = HTTPS only, set false for local dev)
                true,                      // httpOnly (prevent JavaScript access)
                false,                     // raw
                'Lax'                      // sameSite (Lax = good for most cases)
            );

            return $response->cookie($cookie);
        }

        return $response;
    }

    /**
     * Check if token is valid
     */
    public function checkToken(Request $request)
    {
        try {
            // Try header first, then cookie
            $user = Auth::guard('api')->user();

            if (!$user && $request->hasCookie('access_token')) {
                $token = $request->cookie('access_token');
                JWTAuth::setToken($token);
                $user = JWTAuth::authenticate();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'message' => 'Token is invalid'
                ], 401);
            }

            $payload = Auth::guard('api')->payload();
            $expiresAt = $payload->get('exp');
            $now = time();
            $remainingSeconds = $expiresAt - $now;

            return response()->json([
                'success' => true,
                'valid' => true,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'remaining_seconds' => $remainingSeconds,
                'remaining_days' => round($remainingSeconds / 86400, 1),
                'user_id' => $user->id,
                'user_role' => $user->role,
                'stored_in' => $request->hasCookie('access_token') ? 'cookie' : 'header',
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
