<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TicketReplyController;
use App\Http\Controllers\AttachmentDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('tickets/{ticket}/replies', [TicketReplyController::class, 'index'])->name('tickets.replies.index');
    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->middleware('throttle:uploads')->name('tickets.replies.store');
    Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
});
