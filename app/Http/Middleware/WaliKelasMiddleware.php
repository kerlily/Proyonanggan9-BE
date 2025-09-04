<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WaliKelasMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // ensure user is authenticated as guru (api guard)
        $user = Auth::guard('api')->user();
        if (! $user || $user->role !== 'guru') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // get guru model
        $guru = $user->guru; // expects relation user->guru()

        if (! $guru) {
            return response()->json(['message' => 'Forbidden: not a guru record'], 403);
        }

        // kelas_id can be route param or request field
        $kelasId = $request->route('kelas_id') ?? $request->input('kelas_id');

        if (! $kelasId) {
            return response()->json(['message' => 'kelas_id required'], 400);
        }

        // check if this guru is wali for that kelas in active tahun_ajaran
        $tahun = \DB::table('tahun_ajaran')->where('is_active', true)->first();
        if (! $tahun) {
            return response()->json(['message' => 'No active academic year'], 500);
        }

        $isWali = \DB::table('wali_kelas')
            ->where('guru_id', $guru->id)
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahun->id)
            ->exists();

        if (! $isWali) {
            return response()->json(['message' => 'Forbidden: not wali kelas for this class'], 403);
        }

        return $next($request);
    }
}
