<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleMiddleware
{
    /**
     * Expect parameter like: role:admin,guru
     */
    public function handle(Request $request, Closure $next, string $roles = null)
    {
        $user = auth()->guard('api')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (empty($roles)) {
            return $next($request);
        }

        $allowed = array_map(fn($r) => Str::lower(trim($r)), explode(',', $roles));

        if (! in_array(Str::lower($user->role), $allowed, true)) {
            return response()->json(['message' => 'Forbidden: role not allowed'], 403);
        }

        return $next($request);
    }
}
