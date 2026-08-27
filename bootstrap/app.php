<?php

use App\Http\Middleware\EnsureAnyPermission;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company' => ResolveCurrentCompany::class,
            'module' => EnsureModuleEnabled::class,
            'permission.any' => EnsureAnyPermission::class,
            'permission' => EnsurePermission::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
