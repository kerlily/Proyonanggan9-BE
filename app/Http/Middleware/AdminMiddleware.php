<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->guard('api')->user();
        if (! $user || ! method_exists($user, 'isAdmin') || ! $user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return $next($request);
    }
}
