<?php

use App\Http\Controllers\RestoreStatusController;
use App\Http\Middleware\Api\EnsureApiAccount;
use App\Http\Middleware\Api\ResolveApiRole;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureRoleIsSelected;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The progress of a restore (data-manage D51): read while SIAKAD is down and its database is being
        // replaced, so without sessions, cookies or the database cache; the token is the only key.
        then: function (): void {
            Route::get('restore-status/{token}', RestoreStatusController::class)
                ->where('token', '[A-Za-z0-9]{40}')
                ->name('restore.status');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'role.selected' => EnsureRoleIsSelected::class,
            'role' => EnsureRole::class,
            'api.account' => EnsureApiAccount::class,
            'api.role' => ResolveApiRole::class,
        ]);

        $middleware->preventRequestsDuringMaintenance(except: ['restore-status/*']);

        // Everything behind login: active account, permanent password, a chosen role.
        $middleware->group('portal', [
            'auth',
            'active',
            'password.changed',
            'role.selected',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
