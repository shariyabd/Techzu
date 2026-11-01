<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\SavedReply;
use App\Models\User;
use App\Services\SavedReplies\SavedReplyQuery;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
    $this->otherAgent = User::factory()->agent()->create();
    $this->customer = User::factory()->customer()->create();
});

it('authorizes saved reply management by ownership and the shared permission', function (string $actor, string $action, string $target, int $status): void {
    $replies = [
        'own' => SavedReply::factory()->personal($this->{$actor})->create(),
        'other agent' => SavedReply::factory()->personal($this->otherAgent)->create(),
        'shared' => SavedReply::factory()->shared()->create(),
    ];
    $payload = ['title' => 'Greeting', 'body' => 'Hello {customer_name}'];
    Sanctum::actingAs($this->{$actor}, ['*']);

    $response = match ($action) {
        'list' => $this->getJson(route('api.v1.saved-replies.index')),
        'create' => $this->postJson(route('api.v1.saved-replies.store'), [...$payload, 'shared' => $target === 'shared']),
        'update' => $this->patchJson(route('api.v1.saved-replies.update', $replies[$target]), $payload),
        'delete' => $this->deleteJson(route('api.v1.saved-replies.destroy', $replies[$target])),
    };

    $response->assertStatus($status);
})->with([
    'agent creates a personal reply' => ['agent', 'create', 'own', 201],
    'agent updates own personal reply' => ['agent', 'update', 'own', 200],
    'agent deletes own personal reply' => ['agent', 'delete', 'own', 204],
    'agent cannot update another agent\'s reply' => ['agent', 'update', 'other agent', 404],
    'agent cannot delete another agent\'s reply' => ['agent', 'delete', 'other agent', 404],
    'agent cannot create a shared reply' => ['agent', 'create', 'shared', 403],
    'agent cannot update a shared reply' => ['agent', 'update', 'shared', 403],
    'agent cannot delete a shared reply' => ['agent', 'delete', 'shared', 403],
    'admin creates a shared reply' => ['admin', 'create', 'shared', 201],
    'admin updates a shared reply' => ['admin', 'update', 'shared', 200],
    'admin deletes a shared reply' => ['admin', 'delete', 'shared', 204],
    'admin cannot update another user\'s personal reply' => ['admin', 'update', 'other agent', 404],
    'customer cannot list' => ['customer', 'list', 'shared', 403],
    'customer cannot create' => ['customer', 'create', 'own', 403],
    'customer cannot update a shared reply' => ['customer', 'update', 'shared', 403],
    'customer cannot delete a shared reply' => ['customer', 'delete', 'shared', 403],
]);

it('denies customers the saved replies page and picker', function (string $route): void {
    $this->actingAs($this->customer)->get(route($route))->assertForbidden();
})->with(['saved-replies.index', 'saved-replies.picker']);

it('lists only the user\'s own and shared replies', function (): void {
    $own = SavedReply::factory()->personal($this->agent)->create(['title' => 'A own']);
    $shared = SavedReply::factory()->shared()->create(['title' => 'B shared']);
    SavedReply::factory()->personal($this->otherAgent)->create(['title' => 'C hidden']);
    Sanctum::actingAs($this->agent, ['*']);

    expect($this->getJson(route('api.v1.saved-replies.index'))->assertOk()->json('data.*.id'))->toBe([$own->id, $shared->id])
        ->and($this->getJson(route('api.v1.saved-replies.index', ['scope' => 'mine']))->json('data.*.id'))->toBe([$own->id])
        ->and($this->getJson(route('api.v1.saved-replies.index', ['scope' => 'team']))->json('data.*.id'))->toBe([$shared->id]);

    $this->actingAs($this->agent)->getJson(route('saved-replies.picker'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$own->id, $shared->id]);
});

it('stores a personal reply for its author, never from request data', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->postJson(route('api.v1.saved-replies.store'), [
        'title' => 'Refund', 'body' => 'Hi {customer_first_name}', 'user_id' => $this->otherAgent->id,
    ])->assertCreated()->assertJsonPath('data.scope', 'mine');

    expect(SavedReply::query()->sole()->user_id)->toBe($this->agent->id);
});

it('accepts only known placeholders in the body', function (string $body, bool $valid): void {
    Sanctum::actingAs($this->agent, ['*']);

    $response = $this->postJson(route('api.v1.saved-replies.store'), ['title' => 'Reply', 'body' => $body]);

    $valid ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('body');
})->with([
    'every known placeholder' => ['{customer_name} {customer_first_name} {ticket_number} {ticket_subject} {agent_name} {agent_first_name}', true],
    'no placeholders' => ['Thanks for waiting.', true],
    'braces that are not a placeholder' => ['Use { } or {two words} freely.', true],
    'unknown placeholder' => ['Hi {customer_email}', false],
    'wrong case' => ['Hi {Customer_Name}', false],
    'unknown next to known' => ['Hi {customer_name}, ref {order_id}', false],
]);

it('searches replies by title or body without leaking other users\' replies', function (): void {
    $byTitle = SavedReply::factory()->personal($this->agent)->create(['title' => 'Refund policy', 'body' => 'Body']);
    $byBody = SavedReply::factory()->shared()->create(['title' => 'Billing', 'body' => 'We issued a refund today.']);
    SavedReply::factory()->personal($this->otherAgent)->create(['title' => 'Refund secret']);
    SavedReply::factory()->shared()->create(['title' => 'Password reset', 'body' => 'Reset link']);
    Sanctum::actingAs($this->agent, ['*']);

    expect($this->getJson(route('api.v1.saved-replies.index', ['q' => 'refund']))->json('data.*.id'))
        ->toEqualCanonicalizing([$byTitle->id, $byBody->id]);

    $this->actingAs($this->agent)->getJson(route('saved-replies.picker', ['q' => '100%']))->assertOk()->assertJsonCount(0, 'data');
});

it('puts replies for the ticket category first in the picker', function (): void {
    $category = Category::factory()->create();
    SavedReply::factory()->shared()->create(['title' => 'A general']);
    $match = SavedReply::factory()->personal($this->agent)->create(['title' => 'Z category', 'category_id' => $category->id]);

    $this->actingAs($this->agent)->getJson(route('saved-replies.picker', ['category_id' => $category->id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('data.0.category.id', $category->id);
});

it('bounds list pages and the picker result size', function (): void {
    SavedReply::factory()->shared()->count(SavedReplyQuery::PICKER_LIMIT + 1)->create();
    Sanctum::actingAs($this->agent, ['*']);

    $this->getJson(route('api.v1.saved-replies.index', ['per_page' => 101]))->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson(route('api.v1.saved-replies.index', ['per_page' => 100]))->assertOk()->assertJsonCount(SavedReplyQuery::PICKER_LIMIT + 1, 'data');
    $this->actingAs($this->agent)->getJson(route('saved-replies.picker'))->assertOk()->assertJsonCount(SavedReplyQuery::PICKER_LIMIT, 'data');
});

it('renders the management page on the personal tab and saves from the web form', function (): void {
    SavedReply::factory()->personal($this->agent)->create();
    SavedReply::factory()->shared()->create();

    $this->actingAs($this->agent)->get(route('saved-replies.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('saved-replies/Index', false)
            ->where('filters.scope', 'mine')
            ->where('canManageShared', false)
            ->has('replies.data', 1)
            ->where('replies.data.0.can.update', true));

    $this->actingAs($this->agent)->from(route('saved-replies.index'))
        ->post(route('saved-replies.store'), ['title' => 'Hi', 'body' => 'Hello {agent_first_name}'])
        ->assertRedirect(route('saved-replies.index'))
        ->assertSessionHas('success');
});
