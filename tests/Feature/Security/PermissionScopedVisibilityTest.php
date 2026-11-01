<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateRolePermissions;
use App\Actions\Conversation\AddReply;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketQueueCounts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $category = Category::factory()->create();
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent([$category->id])->create();
    $this->admin = User::factory()->admin()->create();
    $this->own = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'category_id' => $category->id]);
    $this->other = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $category->id]);

    app(AddReply::class)->handle($this->agent, $this->own, 'PUBLIC-ANSWER', false, [
        UploadedFile::fake()->create('public-file.pdf', 2, 'application/pdf'),
    ]);
    app(AddReply::class)->handle($this->agent, $this->own, 'SECRET-INTERNAL-NOTE', true, [
        UploadedFile::fake()->create('secret-file.pdf', 2, 'application/pdf'),
    ]);
});

it('keeps lists, queue counts, ticket pages, replies and activities in line with an edited permission matrix', function (
    Role $role,
    array $revoked,
    array $granted,
    string $viewer,
    ?array $expectedVisible,
    bool $expectedInternal,
): void {
    $permissions = [...array_filter(Permission::forRole($role), static fn (Permission $permission): bool => ! in_array($permission, $revoked, true)), ...$granted];
    app(UpdateRolePermissions::class)->handle($role, array_values(array_map(static fn (Permission $permission): string => $permission->value, $permissions)));
    $user = $this->{$viewer};
    $tickets = ['own' => $this->own, 'other' => $this->other];

    $index = $this->actingAs($user)->get(route('tickets.index', ['scope' => 'active']))->assertStatus($expectedVisible === null ? 403 : 200);
    $expectedVisible ??= [];

    if ($index->isOk()) {
        $listed = collect($index->inertiaProps('tickets.data'))->pluck('number')->sort()->values()->all();
        $expectedNumbers = collect($expectedVisible)->map(fn (string $key): string => $tickets[$key]->number)->sort()->values()->all();

        expect($listed)->toBe($expectedNumbers);
    }

    expect(app(TicketQueueCounts::class)->for($user)['active']['total'])->toBe(count($expectedVisible));

    foreach ($tickets as $key => $ticket) {
        $visible = in_array($key, $expectedVisible, true);
        $page = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertStatus($visible ? 200 : 404);

        Sanctum::actingAs($user, ['*']);
        $api = $this->getJson(route('api.v1.tickets.replies.index', $ticket))->assertStatus($visible ? 200 : 404);

        if (! $visible || $key !== 'own') {
            continue;
        }

        $replyBodies = collect($page->inertiaProps('replies'))->pluck('body')->all();
        $attachmentNames = collect($page->inertiaProps('replies'))->pluck('attachments')->flatten(1)->pluck('original_name')->all();
        $activityTypes = collect($page->inertiaProps('activities'))->pluck('type')->all();

        expect(in_array('SECRET-INTERNAL-NOTE', $replyBodies, true))->toBe($expectedInternal)
            ->and(in_array('secret-file.pdf', $attachmentNames, true))->toBe($expectedInternal)
            ->and(in_array(ActivityType::InternalNote->value, $activityTypes, true))->toBe($expectedInternal)
            ->and($replyBodies)->toContain('PUBLIC-ANSWER')
            ->and($attachmentNames)->toContain('public-file.pdf')
            ->and(collect($api->json('data'))->pluck('body')->contains('SECRET-INTERNAL-NOTE'))->toBe($expectedInternal);
    }
})->with([
    'agent, matrix as seeded' => [Role::Agent, [], [], 'agent', ['own', 'other'], true],
    'agent without internal notes' => [Role::Agent, [Permission::TicketsNotesInternal], [], 'agent', ['own', 'other'], false],
    'agent with view-own instead of view-all' => [Role::Agent, [Permission::TicketsViewAny], [Permission::TicketsViewOwn], 'agent', [], true],
    'agent with no ticket view permission' => [Role::Agent, [Permission::TicketsViewAny], [], 'agent', null, true],
    'admin without view-all or internal notes' => [Role::Admin, [Permission::TicketsViewAny, Permission::TicketsNotesInternal], [Permission::TicketsViewOwn], 'admin', [], false],
    'customer, matrix as seeded' => [Role::Customer, [], [], 'customer', ['own'], false],
    'customer without view-own' => [Role::Customer, [Permission::TicketsViewOwn], [], 'customer', null, false],
]);
