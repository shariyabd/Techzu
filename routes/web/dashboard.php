<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('dashboard', fn (): Response => Inertia::render('Dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
