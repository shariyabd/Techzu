<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\SlaSettingsController;
use App\Models\SlaSetting;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('sla', [SlaSettingsController::class, 'edit'])->name('sla.edit')->can('view', SlaSetting::class);
    Route::put('sla', [SlaSettingsController::class, 'update'])->name('sla.update')->can('update', SlaSetting::class);
});
