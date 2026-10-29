<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\TicketImportController;
use App\Models\TicketImport;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin/imports')->name('admin.imports.')->group(function (): void {
    Route::get('/', [TicketImportController::class, 'index'])->name('index')->can('viewAny', TicketImport::class);
    Route::get('create', [TicketImportController::class, 'create'])->name('create')->can('create', TicketImport::class);
    Route::post('/', [TicketImportController::class, 'store'])->name('store')->middleware('throttle:uploads')->can('create', TicketImport::class);
    Route::get('{import}', [TicketImportController::class, 'show'])->name('show')->can('view', 'import');
    Route::get('{import}/errors/download', [TicketImportController::class, 'downloadErrors'])->name('errors.download')->can('view', 'import');
});
