<?php

declare(strict_types=1);

use App\Http\Controllers\Web\SavedReplyController;
use App\Models\SavedReply;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('saved-replies', [SavedReplyController::class, 'index'])->name('saved-replies.index')->can('viewAny', SavedReply::class);
    Route::get('saved-replies/picker', [SavedReplyController::class, 'picker'])->middleware('throttle:60,1')->name('saved-replies.picker')->can('viewAny', SavedReply::class);
    Route::post('saved-replies', [SavedReplyController::class, 'store'])->name('saved-replies.store')->can('create', SavedReply::class);
    Route::patch('saved-replies/{savedReply}', [SavedReplyController::class, 'update'])->name('saved-replies.update')->can('update', 'savedReply');
    Route::delete('saved-replies/{savedReply}', [SavedReplyController::class, 'destroy'])->name('saved-replies.destroy')->can('delete', 'savedReply');
});
