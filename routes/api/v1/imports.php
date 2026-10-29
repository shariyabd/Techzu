<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TicketImportController;
use App\Models\TicketImport;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('imports', [TicketImportController::class, 'store'])->name('imports.store')->middleware('throttle:uploads')->can('create', TicketImport::class);
    Route::get('imports/{import}', [TicketImportController::class, 'show'])->name('imports.show')->can('view', 'import');
    Route::get('imports/{import}/errors', [TicketImportController::class, 'errors'])->name('imports.errors.index')->can('view', 'import');
});
