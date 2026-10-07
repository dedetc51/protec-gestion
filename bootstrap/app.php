<?php

use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/up', function () {
                try {
                    DB::select('select 1');

                    return response('OK', 200, ['Content-Type' => 'text/plain']);
                } catch (Throwable) {
                    return response('Unavailable', 503, ['Content-Type' => 'text/plain']);
                }
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['192.168.1.240']);
        $middleware->alias(['password.changed' => RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
