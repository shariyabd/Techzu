<?php

declare(strict_types=1);

use App\Http\Controllers\Web\NotificationController;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->can('viewAny', DatabaseNotification::class)->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->name('notifications.read');
});
