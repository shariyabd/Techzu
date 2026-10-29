<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\Web\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->middleware('throttle:uploads')->name('tickets.replies.store');
    Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
});
