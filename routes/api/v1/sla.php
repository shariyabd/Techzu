<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\SlaSettingsController;
use App\Models\SlaSetting;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('sla', [SlaSettingsController::class, 'show'])->name('sla.show')->can('view', SlaSetting::class);
    Route::put('sla', [SlaSettingsController::class, 'update'])->name('sla.update')->can('update', SlaSetting::class);
});
