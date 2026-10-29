<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

function ensureTicketsIndexRoute(): void
{
    if (Route::has('tickets.index')) {
        return;
    }

    Route::middleware('web')->get('/tickets', fn (): string => 'tickets')->name('tickets.index');
    Route::getRoutes()->refreshNameLookups();
}

it('sends guests to the login page', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends users who may view the dashboard to it', function (): void {
    Gate::define('viewDashboard', fn (User $user): bool => true);

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
});

it('sends everyone else to their ticket list', function (): void {
    Gate::define('viewDashboard', fn (User $user): bool => false);
    ensureTicketsIndexRoute();

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('tickets.index'));
});

it('falls back to the dashboard placeholder while tickets.index does not exist', function (): void {
    Gate::define('viewDashboard', fn (User $user): bool => false);

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
})->skip(fn (): bool => Route::has('tickets.index'), 'tickets.index exists (T05 merged)');
