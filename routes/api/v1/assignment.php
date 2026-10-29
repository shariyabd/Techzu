<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('tickets/{ticket}/claim', [AssignmentController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/assign', [AssignmentController::class, 'assign'])->name('tickets.assign');
});
