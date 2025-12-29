<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogAuthActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->is('api/auth/login')) {
            $this->logAuthAttempt($request, $response);
        }

        if ($request->is('api/auth/logout')) {
            $this->logLogout($request);
        }

        return $response;
    }

    protected function logAuthAttempt($request, $response)
    {
        $status = $response->getStatusCode();

        if ($status === 200) {
            $data = json_decode($response->getContent(), true);
            $user = $data['user'] ?? null;

            if ($user) {
                activity()
                    ->causedBy(\App\Models\User::find($user['id']))
                    ->withProperties([
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'user_type' => 'user',
                    ])
                    ->log('Login successful');
            }
        } else {
            activity()
                ->withProperties([
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'credentials' => $request->only(['email']),
                    'user_type' => 'user',
                ])
                ->log('Login failed');
        }
    }

    protected function logLogout($request)
    {
        $user = auth('api')->user();

        if ($user) {
            activity()
                ->causedBy($user)
                ->withProperties([
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'user_type' => 'user',
                ])
                ->log('Logout');
        }
    }
}
