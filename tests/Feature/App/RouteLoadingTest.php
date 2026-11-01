<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('loads the web routes inside the web middleware group', function (): void {
    $route = Route::getRoutes()->getByName('dashboard');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('dashboard')
        ->and($route->gatherMiddleware())->toContain('web');
});
