<?php

declare(strict_types=1);

use App\Http\Controllers\Web\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:uploads')->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::patch('tickets/{ticket}/status', [TicketController::class, 'status'])->name('tickets.status');
    Route::patch('tickets/{ticket}/priority', [TicketController::class, 'priority'])->name('tickets.priority');
});
