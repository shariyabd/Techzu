<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Enums\TicketStatus;
use App\Http\Resources\V1\TicketReplyResource;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    app(AddReply::class)->handle($this->agent, $this->ticket, 'PUBLIC-ANSWER', false);
    $this->internal = app(AddReply::class)->handle($this->agent, $this->ticket, 'SECRET-INTERNAL-NOTE', true, [
        UploadedFile::fake()->create('secret-file.pdf', 2, 'application/pdf'),
    ]);
});

it('shows internal notes on the show page and the replies API only to staff', function (string $viewer, int $expected): void {
    $user = $viewer === 'customer' ? $this->customer : $this->agent;
    $this->actingAs($user)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Show')->has('replies', $expected));
    Sanctum::actingAs($user, ['*']);
    $this->getJson(route('api.v1.tickets.replies.index', $this->ticket))
        ->assertOk()
        ->assertJsonCount($expected, 'data');
})->with([
    'customer' => ['customer', 1],
    'agent' => ['agent', 2],
]);

it('never sends a customer internal content from any page or endpoint', function (string $guard, string $routeName): void {
    $uri = route($routeName, in_array($routeName, ['tickets.index', 'api.v1.tickets.index'], true) ? [] : $this->ticket);
    if ($guard === 'api') {
        Sanctum::actingAs($this->customer, ['*']);
        $response = $this->getJson($uri);
    } else {
        $response = $this->actingAs($this->customer)->get($uri);
    }
    $response->assertOk()
        ->assertDontSee('SECRET-INTERNAL-NOTE')
        ->assertDontSee('secret-file.pdf')
        ->assertDontSee('internal_note');
})->with([
    'web ticket page' => ['web', 'tickets.show'],
    'web ticket list' => ['web', 'tickets.index'],
    'api ticket' => ['api', 'api.v1.tickets.show'],
    'api ticket list' => ['api', 'api.v1.tickets.index'],
    'api replies' => ['api', 'api.v1.tickets.replies.index'],
    'api activities' => ['api', 'api.v1.tickets.activities'],
]);

it('counts only public replies in a customer ticket list', function (): void {
    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.tickets.index'))
        ->assertOk()
        ->assertJsonPath('data.0.replies_count', 1);
});

it('refuses to serialize an internal reply for a customer even when a query forgets visibleTo', function (): void {
    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $this->customer);
    $this->internal->setRelation('ticket', $this->ticket);
    expect(fn () => TicketReplyResource::make($this->internal)->toArray($request))
        ->toThrow(AuthorizationException::class);
});
