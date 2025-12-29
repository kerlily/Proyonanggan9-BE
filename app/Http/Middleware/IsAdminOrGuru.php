<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsAdminOrGuru
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->guard('api')->user();

        if (!$user || !in_array($user->role, ['admin', 'guru'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
