<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
});

it('enforces the conversation matrix', function (?Role $role, string $relation, string $method, string $target, bool $closed, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $actor = $role === null ? null : $matrix->actingAsRole($role);
    if ($actor === null) {
        $matrix->asGuest();
    }
    $owner = $relation === 'own' && $actor !== null ? $actor : User::factory()->customer()->create();
    $writer = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($writer)->create([
        'customer_id' => $owner->id,
        'created_by_id' => $owner->id,
    ]);
    $public = app(AddReply::class)->handle($writer, $ticket, 'Public', false, [UploadedFile::fake()->create('public.pdf', 2, 'application/pdf')]);
    $internal = app(AddReply::class)->handle($writer, $ticket, 'Internal', true, [UploadedFile::fake()->create('internal.pdf', 2, 'application/pdf')]);
    $publicFile = Attachment::query()->where('reply_id', $public->id)->sole();
    $internalFile = Attachment::query()->where('reply_id', $internal->id)->sole();
    if ($closed) {
        $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();
    }
    $uri = match ($target) {
        'web.reply' => route('tickets.replies.store', $ticket),
        'api.reply' => route('api.v1.tickets.replies.store', $ticket),
        'api.replies' => route('api.v1.tickets.replies.index', $ticket),
        'web.public_file' => route('attachments.download', $publicFile),
        'api.public_file' => route('api.v1.attachments.download', $publicFile),
        'web.internal_file' => route('attachments.download', $internalFile),
        'api.internal_file' => route('api.v1.attachments.download', $internalFile),
    };
    $matrix->expectAccess($method, $uri, $status, $method === 'POST' ? ['body' => 'matrix reply'] : []);
})->with([
    'guest · web reply' => [null, 'other', 'POST', 'web.reply', false, 302],
    'customer · own · web reply' => [Role::Customer, 'own', 'POST', 'web.reply', false, 302],
    'customer · other · web reply' => [Role::Customer, 'other', 'POST', 'web.reply', false, 404],
    'agent · web reply' => [Role::Agent, 'other', 'POST', 'web.reply', false, 302],
    'admin · web reply' => [Role::Admin, 'other', 'POST', 'web.reply', false, 302],
    'admin · web reply · closed' => [Role::Admin, 'other', 'POST', 'web.reply', true, 403],
    'guest · api reply' => [null, 'other', 'POST', 'api.reply', false, 401],
    'customer · own · api reply' => [Role::Customer, 'own', 'POST', 'api.reply', false, 201],
    'customer · other · api reply' => [Role::Customer, 'other', 'POST', 'api.reply', false, 404],
    'agent · api reply' => [Role::Agent, 'other', 'POST', 'api.reply', false, 201],
    'customer · own · api reply · closed' => [Role::Customer, 'own', 'POST', 'api.reply', true, 403],
    'agent · api reply · closed' => [Role::Agent, 'other', 'POST', 'api.reply', true, 403],
    'guest · api replies' => [null, 'other', 'GET', 'api.replies', false, 401],
    'customer · own · api replies' => [Role::Customer, 'own', 'GET', 'api.replies', false, 200],
    'customer · other · api replies' => [Role::Customer, 'other', 'GET', 'api.replies', false, 404],
    'agent · api replies' => [Role::Agent, 'other', 'GET', 'api.replies', false, 200],
    'guest · web public file' => [null, 'other', 'GET', 'web.public_file', false, 302],
    'customer · own · web public file' => [Role::Customer, 'own', 'GET', 'web.public_file', false, 200],
    'customer · other · web public file' => [Role::Customer, 'other', 'GET', 'web.public_file', false, 404],
    'agent · web public file' => [Role::Agent, 'other', 'GET', 'web.public_file', false, 200],
    'guest · api public file' => [null, 'other', 'GET', 'api.public_file', false, 401],
    'customer · own · api public file' => [Role::Customer, 'own', 'GET', 'api.public_file', false, 200],
    'customer · other · api public file' => [Role::Customer, 'other', 'GET', 'api.public_file', false, 404],
    'customer · own · web internal file' => [Role::Customer, 'own', 'GET', 'web.internal_file', false, 404],
    'customer · own · api internal file' => [Role::Customer, 'own', 'GET', 'api.internal_file', false, 404],
    'agent · web internal file' => [Role::Agent, 'other', 'GET', 'web.internal_file', false, 200],
    'admin · api internal file' => [Role::Admin, 'other', 'GET', 'api.internal_file', false, 200],
]);
