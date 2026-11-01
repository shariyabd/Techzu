<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SavedReplyController;
use App\Models\SavedReply;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('saved-replies', [SavedReplyController::class, 'index'])->name('saved-replies.index')->can('viewAny', SavedReply::class);
    Route::post('saved-replies', [SavedReplyController::class, 'store'])->name('saved-replies.store')->can('create', SavedReply::class);
    Route::patch('saved-replies/{savedReply}', [SavedReplyController::class, 'update'])->name('saved-replies.update')->can('update', 'savedReply');
    Route::delete('saved-replies/{savedReply}', [SavedReplyController::class, 'destroy'])->name('saved-replies.destroy')->can('delete', 'savedReply');
});
