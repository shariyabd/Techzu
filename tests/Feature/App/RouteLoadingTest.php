<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('loads feature route files from routes/web inside the web middleware group', function (): void {
    $route = Route::getRoutes()->getByName('dashboard');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('dashboard')
        ->and($route->gatherMiddleware())->toContain('web');
});
