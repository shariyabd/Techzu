<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\AssignmentMethod;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Holiday;
use App\Models\SavedReply;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoFiles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

function demoActivities(ActivityType $type): int
{
    return Activity::query()->where('log_name', 'ticket')->where('event', $type->value)->count();
}

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
});

it('seeds a demo dataset that covers every scenario of the presentation, after removing files left by an earlier seed', function (): void {
    Http::fake(['images.unsplash.com/*' => Http::response((new DemoFiles)->png('portrait', 320, 320), 200, ['Content-Type' => 'image/png'])]);
    config(['mail.default' => 'log', 'queue.default' => 'database', 'tickets.auto_assign' => true]);
    $orphans = ['avatars/orphan.png', 'attachments/orphan.pdf', 'imports/orphan.csv'];

    foreach ($orphans as $orphan) {
        Storage::disk('local')->put($orphan, 'left over');
    }

    $this->seed();

    foreach ($orphans as $orphan) {
        Storage::disk('local')->assertMissing($orphan);
    }

    $accounts = [
        'admin@example.com' => Role::Admin,
        'agent1@example.com' => Role::Agent,
        'agent2@example.com' => Role::Agent,
        'agent3@example.com' => Role::Agent,
        'agent4@example.com' => Role::Agent,
        'customer1@example.com' => Role::Customer,
        'customer5@example.com' => Role::Customer,
    ];

    foreach ($accounts as $email => $role) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->hasRole($role->value))->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeTrue();
    }

    expect(User::query()->where('email', 'agent4@example.com')->value('is_available'))->toBeFalse()
        ->and(User::role(Role::Agent->value)->count())->toBeGreaterThanOrEqual(6)
        ->and(User::role(Role::Customer->value)->count())->toBeGreaterThanOrEqual(12);

    $staff = User::role([Role::Admin->value, Role::Agent->value])->get(['id', 'avatar_path']);

    expect($staff->whereNull('avatar_path'))->toBeEmpty();
    $staff->each(fn (User $user) => Storage::disk('local')->assertExists((string) $user->avatar_path));

    expect(Ticket::query()->count())->toBeGreaterThanOrEqual(80);

    foreach (TicketStatus::cases() as $status) {
        expect(Ticket::query()->where('status', $status->value)->exists())->toBeTrue("No {$status->value} ticket was seeded.");
    }

    $now = CarbonImmutable::now();

    foreach (SlaState::cases() as $state) {
        expect(SlaQuery::whereState(Ticket::query(), $state, $now)->exists())->toBeTrue("No ticket is {$state->value}.");
    }

    expect(Ticket::query()->doesntHave('replies')->exists())->toBeTrue()
        ->and(Ticket::query()->whereHas('replies', fn ($replies) => $replies->where('is_internal', false), '>=', 2)->exists())->toBeTrue()
        ->and(Ticket::query()->whereHas('replies', fn ($replies) => $replies->where('is_internal', true))->exists())->toBeTrue()
        ->and(Ticket::query()->whereNull('assigned_agent_id')->where('status', TicketStatus::Open->value)->doesntHave('replies')->exists())->toBeTrue()
        ->and(Attachment::query()->whereNull('reply_id')->exists())->toBeTrue()
        ->and(Attachment::query()->whereNotNull('reply_id')->where('is_internal', false)->exists())->toBeTrue()
        ->and(Attachment::query()->whereNotNull('reply_id')->where('is_internal', true)->exists())->toBeTrue()
        ->and(Ticket::query()->doesntHave('attachments')->exists())->toBeTrue();

    foreach ([ActivityType::Assigned, ActivityType::Claimed, ActivityType::SlaApproaching, ActivityType::Escalated, ActivityType::SlaPaused, ActivityType::PriorityChanged, ActivityType::CategoryChanged, ActivityType::InternalNote] as $type) {
        expect(demoActivities($type))->toBeGreaterThan(0, "No {$type->value} activity was recorded.");
    }

    $assignments = Activity::query()->where('event', ActivityType::Assigned->value)->get(['properties']);

    expect($assignments->filter(fn (Activity $activity): bool => $activity->getExtraProperty('method') === AssignmentMethod::Automatic->value))->not->toBeEmpty()
        ->and($assignments->filter(fn (Activity $activity): bool => $activity->getExtraProperty('method') === AssignmentMethod::Manual->value && $activity->getExtraProperty('from') !== null))->not->toBeEmpty()
        ->and(Activity::query()->where('event', ActivityType::StatusChanged->value)->where('properties->from', TicketStatus::Resolved->value)->where('properties->to', TicketStatus::InProgress->value)->exists())->toBeTrue()
        ->and(Ticket::query()->whereColumn('created_by_id', '!=', 'customer_id')->whereNull('ticket_import_id')->exists())->toBeTrue();

    expect(SavedReply::query()->whereNull('user_id')->count())->toBeGreaterThanOrEqual(6)
        ->and(SavedReply::query()->whereNotNull('user_id')->count())->toBeGreaterThanOrEqual(2)
        ->and(Holiday::query()->where('date', '>', $now->toDateString())->count())->toBeGreaterThanOrEqual(3);

    $import = TicketImport::query()->withCount('errors')->sole();

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->errors_count)->toBeGreaterThanOrEqual(2)
        ->and($import->succeeded_rows)->toBeGreaterThan(0);

    expect(CarbonImmutable::hasTestNow())->toBeFalse()
        ->and(config('mail.default'))->toBe('log')
        ->and(config('queue.default'))->toBe('database')
        ->and(config('tickets.auto_assign'))->toBeTrue();
});

it('still seeds the demo when the photo downloads fail, and only once without touching the stored files', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    $this->seed();
    $tickets = Ticket::query()->count();
    $users = User::query()->count();
    Storage::disk('local')->put('attachments/live.pdf', 'customer upload');
    $this->seed();

    Storage::disk('local')->assertExists('attachments/live.pdf');
    expect($tickets)->toBeGreaterThanOrEqual(80)
        ->and(Ticket::query()->count())->toBe($tickets)
        ->and(User::query()->count())->toBe($users)
        ->and(User::query()->whereNotNull('avatar_path')->exists())->toBeFalse();
});

it('does not seed demo data outside local and testing environments', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->exists())->toBeFalse()
        ->and(Ticket::query()->exists())->toBeFalse();
});
