<?php

declare(strict_types=1);

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: array_merge(
            [__DIR__.'/../routes/web.php'],
            glob(__DIR__.'/../routes/web/*.php') ?: [],
        ),
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            foreach (glob(__DIR__.'/../routes/api/v1/*.php') ?: [] as $file) {
                Route::middleware('api')
                    ->prefix('api/v1')
                    ->name('api.v1.')
                    ->group($file);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(DomainException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $exception, Request $request): JsonResponse|RedirectResponse {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => $exception->errorCode(),
                ], $exception->status());
            }

            return back()->with('error', $exception->getMessage());
        });
    })->create();
