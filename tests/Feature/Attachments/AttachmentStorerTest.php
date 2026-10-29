<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('attachments.disk', 'local');
});

it('stores files privately under a uuid name and records metadata', function (): void {
    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->customer()->create();

    $attachments = app(AttachmentStorer::class)->storeMany($ticket, null, $uploader, [
        UploadedFile::fake()->create('Invoice March.pdf', 120, 'application/pdf'),
        UploadedFile::fake()->image('screen.png'),
    ], false);

    expect($attachments)->toHaveCount(2);

    $first = $attachments->first();
    expect($first)->toBeInstanceOf(Attachment::class)
        ->and($first->exists)->toBeTrue()
        ->and($first->ticket_id)->toBe($ticket->id)
        ->and($first->reply_id)->toBeNull()
        ->and($first->uploaded_by)->toBe($uploader->id)
        ->and($first->disk)->toBe('local')
        ->and($first->original_name)->toBe('Invoice March.pdf')
        ->and($first->size_bytes)->toBe(120 * 1024)
        ->and((bool) $first->is_internal)->toBeFalse()
        ->and($first->path)->toMatch('#^attachments/'.$ticket->id.'/[0-9a-f-]{36}\.pdf$#');

    Storage::disk('local')->assertExists($first->path);
    Storage::disk('local')->assertExists($attachments->last()->path);
});

it('deletes already stored files when a row fails to insert', function (): void {
    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->customer()->create();
    $inserted = 0;
    Attachment::created(function () use (&$inserted): void {
        $inserted++;
        if ($inserted === 2) {
            throw new RuntimeException('insert failed');
        }
    });

    expect(fn () => app(AttachmentStorer::class)->storeMany($ticket, null, $uploader, [
        UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 1, 'application/pdf'),
    ], false))->toThrow(RuntimeException::class, 'insert failed');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('deleteFiles removes the stored files for the given attachments', function (): void {
    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->customer()->create();
    $storer = app(AttachmentStorer::class);

    $attachments = $storer->storeMany($ticket, null, $uploader, [
        UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
    ], false);

    $storer->deleteFiles($attachments);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
