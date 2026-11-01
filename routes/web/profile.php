<?php

declare(strict_types=1);

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\Web\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1')->name('profile.password.update');
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:uploads')->name('profile.avatar.update');
    Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
});

Route::middleware('auth:sanctum')->get('avatars/{user}', AvatarController::class)
    ->whereNumber('user')
    ->name('avatars.show');
