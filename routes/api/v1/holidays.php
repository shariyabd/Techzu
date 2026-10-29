<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\HolidayController;
use App\Models\Holiday;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index')->can('viewAny', Holiday::class);
    Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store')->can('create', Holiday::class);
    Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update')->can('update', 'holiday');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy')->can('delete', 'holiday');
});
