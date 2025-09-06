<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CanViewJadwal
{
    /**
     * Allow viewing jadwal when:
     * - authenticated as 'api' user with role guru or admin (they can view all), OR
     * - authenticated as 'siswa' and siswa.kelas_id === route kelas_id
     */
    public function handle(Request $request, Closure $next)
    {
        $kelasId = $request->route('kelas_id') ?? $request->input('kelas_id');

        // 1) If authenticated as API user (admin/guru) => allow
        if (Auth::guard('api')->check()) {
            $user = Auth::guard('api')->user();
            if ($user && in_array($user->role, ['admin','guru'])) {
                return $next($request);
            }
        }

        // 2) If authenticated as siswa => must belong to kelas
        if (Auth::guard('siswa')->check()) {
            $siswa = Auth::guard('siswa')->user();
            if (! $kelasId) {
                return response()->json(['message' => 'kelas_id required'], 400);
            }
            if ((int)$siswa->kelas_id === (int)$kelasId) {
                return $next($request);
            }
            return response()->json(['message' => 'Forbidden: not your class schedule'], 403);
        }

        // otherwise deny
        return response()->json(['message' => 'Unauthorized: please login as siswa/guru/admin'], 401);
    }
}
