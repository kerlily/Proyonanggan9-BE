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
        $middleware->alias([
            'wali.kelas' => \App\Http\Middleware\WaliKelasMiddleware::class,
            'is_admin'   => \App\Http\Middleware\AdminMiddleware::class,
            'is_admin_or_guru' => \App\Http\Middleware\IsAdminOrGuru::class,
            'role'       => \App\Http\Middleware\RoleMiddleware::class,
            'can.view.jadwal' => \App\Http\Middleware\CanViewJadwal::class,
        ]);

        // Log auth activity
        $middleware->append(\App\Http\Middleware\LogAuthActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->create();
