<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogAuthActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->is('api/auth/login') || $request->is('api/siswa/login')) {
            $this->logAuthAttempt($request, $response);
        }

        if ($request->is('api/auth/logout') || $request->is('api/siswa/logout')) {
            $this->logLogout($request);
        }

        return $response;
    }

    protected function logAuthAttempt($request, $response)
    {
        $status = $response->getStatusCode();
        $isSiswa = $request->is('api/siswa/login');
        $userType = $isSiswa ? 'siswa' : 'user';

        if ($status === 200) {
            $data = json_decode($response->getContent(), true);
            $user = $data['user'] ?? null;

            if ($user) {
                activity()
                    ->causedBy($userType === 'siswa' ?
                        \App\Models\Siswa::find($user['id']) :
                        \App\Models\User::find($user['id'])
                    )
                    ->withProperties([
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'user_type' => $userType,
                    ])
                    ->log('Login successful');
            }
        } else {
            activity()
                ->withProperties([
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'credentials' => $request->only(['email', 'nama', 'kelas_id']),
                    'user_type' => $userType,
                ])
                ->log('Login failed');
        }
    }

    protected function logLogout($request)
    {
        $guard = $request->is('api/siswa/logout') ? 'siswa' : 'api';
        $user = auth($guard)->user();

        if ($user) {
            activity()
                ->causedBy($user)
                ->withProperties([
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'user_type' => $guard === 'siswa' ? 'siswa' : 'user',
                ])
                ->log('Logout');
        }
    }
}
