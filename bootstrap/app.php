<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            // Existing middleware
            'wali.kelas' => \App\Http\Middleware\WaliKelasMiddleware::class,
            'is_admin'   => \App\Http\Middleware\AdminMiddleware::class,
            'is_admin_or_guru' => \App\Http\Middleware\IsAdminOrGuru::class,
            'role'       => \App\Http\Middleware\RoleMiddleware::class,
            'can.view.jadwal' => \App\Http\Middleware\CanViewJadwal::class,

            // NEW: JWT auto-refresh middleware
            'jwt.refresh' => \App\Http\Middleware\JwtRefreshToken::class,
        ]);

        // Global middleware untuk semua routes
        // Log auth activity
        $middleware->append(\App\Http\Middleware\LogAuthActivity::class);

        // OPTIONAL: Apply jwt.refresh globally to all API routes
        // Uncomment baris di bawah jika ingin auto-refresh di semua endpoint API
        // $middleware->appendToGroup('api', [
        //     \App\Http\Middleware\JwtRefreshToken::class,
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->create();
