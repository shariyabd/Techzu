<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/tokens', [AuthTokenController::class, 'store'])
    ->middleware('throttle:api-tokens')
    ->name('auth.tokens.store');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');
    Route::get('me', CurrentUserController::class)->name('auth.me');
});
