<?php

declare(strict_types=1);

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
        // middleware alias yang kita gunakan di routes/api.php
        $middleware->alias([
            'wali.kelas' => \App\Http\Middleware\WaliKelasMiddleware::class,
            'is_admin'   => \App\Http\Middleware\AdminMiddleware::class,
             'role'       => \App\Http\Middleware\RoleMiddleware::class,
             'can.view.jadwal' => \App\Http\Middleware\CanViewJadwal::class,
        ]);

        // Jika perlu, tambahkan middleware global atau group di sini, contoh:
        // $middleware->append(\App\Http\Middleware\SomeGlobalMiddleware::class);
        // $middleware->prependToGroup('api', \App\Http\Middleware\SomeApiMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
