<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function (): void {
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1')->name('password.update');
    Route::post('avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:uploads')->name('avatar.update');
    Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
});
