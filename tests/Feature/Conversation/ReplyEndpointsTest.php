<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
});

it('stores a web reply and redirects to the ticket', function (): void {
    $this->actingAs($this->agent)
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Hello from the web.'])
        ->assertRedirect(route('tickets.show', $this->ticket))
        ->assertSessionHas('success');

    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->sole()->body)->toBe('Hello from the web.');
});

it('stores an API reply and returns 201 with the reply resource', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'Hello from the API.',
        'is_internal' => true,
        'attachments' => [UploadedFile::fake()->create('trace.txt', 2, 'text/plain')],
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Hello from the API.')
        ->assertJsonPath('data.is_internal', true)
        ->assertJsonPath('data.author.id', $this->agent->id)
        ->assertJsonPath('data.attachments.0.original_name', 'trace.txt');
});

it('returns 422 ticket_closed when the ticket closes between authorization and the write', function (): void {
    app()->resolving(AddReply::class, function (): void {
        DB::table('tickets')->where('id', $this->ticket->id)->update(['status' => TicketStatus::Closed->value]);
    });

    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Racing the close'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ticket_closed');

    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->exists())->toBeFalse();
});

it('rejects disallowed or oversized files without storing anything', function (Closure $makeFile): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'With a bad file',
        'attachments' => [$makeFile()],
    ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

    expect(TicketReply::query()->count())->toBe(0)
        ->and(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty();
})->with([
    'windows executable' => [fn (): UploadedFile => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')],
    'php script' => [fn (): UploadedFile => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')],
    'executable content behind a .png name' => [fn (): UploadedFile => UploadedFile::fake()->create('photo.png', 10, 'application/x-msdownload')],
    'pdf content behind an .exe name' => [fn (): UploadedFile => UploadedFile::fake()->create('invoice.exe', 10, 'application/pdf')],
    'one kilobyte over the limit' => [fn (): UploadedFile => UploadedFile::fake()->create('big.pdf', (int) config('attachments.max_kb') + 1, 'application/pdf')],
]);

it('lists replies oldest first with pagination meta', function (): void {
    TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create(['body' => 'first', 'created_at' => now()->subHour()]);
    TicketReply::factory()->for($this->ticket)->for($this->customer, 'author')->create(['body' => 'second', 'created_at' => now()]);

    Sanctum::actingAs($this->agent, ['*']);

    $this->getJson(route('api.v1.tickets.replies.index', ['ticket' => $this->ticket, 'per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('data.0.body', 'first')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);
});
