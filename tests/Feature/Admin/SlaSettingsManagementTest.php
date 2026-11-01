<?php

declare(strict_types=1);

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Actions\Admin\UpdateSlaSettings;
use App\Jobs\RecalculateActiveTicketSlas;
use App\Models\Holiday;
use App\Models\SlaSetting;
use App\Models\User;
use App\Services\Sla\SlaSettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function slaSettingsPayload(array $overrides = []): array
{
    return array_replace([
        'resolution_minutes' => ['low' => 1200, 'medium' => 600, 'high' => 240, 'critical' => 60],
        'warning_threshold_percent' => 80,
        'timezone' => 'Europe/London',
        'week' => [
            'monday' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
            'tuesday' => [['start' => '08:00', 'end' => '17:00']],
        ],
    ], $overrides);
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

it('rejects invalid SLA settings and keeps the previous ones', function (array $overrides, string $field): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson(route('api.v1.admin.sla.update'), slaSettingsPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(SlaSetting::query()->exists())->toBeFalse();
})->with([
    'overlapping windows' => [['week' => ['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:30', 'end' => '14:00']]]], 'week.monday.1.start'],
    'window ending before it starts' => [['week' => ['monday' => [['start' => '18:00', 'end' => '09:00']]]], 'week.monday.0.end'],
    'zero-length window' => [['week' => ['monday' => [['start' => '09:00', 'end' => '09:00']]]], 'week.monday.0.end'],
    'malformed time' => [['week' => ['monday' => [['start' => '9:00', 'end' => '17:00']]]], 'week.monday.0.start'],
    'window starting at 24:00' => [['week' => ['monday' => [['start' => '24:00', 'end' => '24:00']]]], 'week.monday.0.start'],
    'time past the end of the day' => [['week' => ['monday' => [['start' => '09:00', 'end' => '24:01']]]], 'week.monday.0.end'],
    'no open window in the week' => [['week' => ['monday' => [], 'friday' => []]], 'week'],
    'unknown weekday' => [['week' => ['funday' => [['start' => '09:00', 'end' => '17:00']]]], 'week'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'threshold of 0' => [['warning_threshold_percent' => 0], 'warning_threshold_percent'],
    'threshold of 100' => [['warning_threshold_percent' => 100], 'warning_threshold_percent'],
    'zero-minute target' => [['resolution_minutes' => ['low' => 0, 'medium' => 600, 'high' => 240, 'critical' => 60]], 'resolution_minutes.low'],
    'target above the limit' => [['resolution_minutes' => ['low' => 43201, 'medium' => 600, 'high' => 240, 'critical' => 60]], 'resolution_minutes.low'],
    'missing priority target' => [['resolution_minutes' => ['low' => 1200, 'medium' => 600, 'high' => 240]], 'resolution_minutes.critical'],
]);

it('accepts 24:00 as the end of a round-the-clock window', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson(route('api.v1.admin.sla.update'), slaSettingsPayload([
        'week' => ['monday' => [['start' => '00:00', 'end' => '24:00']]],
    ]))->assertOk();

    expect(app(SlaSettingsRepository::class)->current()->week['monday'])->toBe([['00:00', '24:00']]);
});

it('saves the settings through the page and shows them back with every weekday', function (): void {
    $this->actingAs($this->admin)
        ->put(route('admin.sla.update'), slaSettingsPayload())
        ->assertRedirect(route('admin.sla.edit'))
        ->assertSessionHas('success');

    $this->actingAs($this->admin)->get(route('admin.sla.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/sla/Edit', false)
            ->where('settings.customized', true)
            ->where('settings.timezone', 'Europe/London')
            ->where('settings.resolution_minutes.critical', 60)
            ->where('settings.week.monday.1', ['start' => '13:00', 'end' => '17:00'])
            ->where('settings.week.sunday', [])
            ->has('settings.week', 7)
            ->has('timezones'));
});

it('returns the stored settings over the API after an update', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.admin.sla.show'))
        ->assertOk()
        ->assertJsonPath('data.customized', false)
        ->assertJsonPath('data.timezone', 'Asia/Dhaka');

    $this->putJson(route('api.v1.admin.sla.update'), slaSettingsPayload())
        ->assertOk()
        ->assertJsonPath('data.customized', true)
        ->assertJsonPath('data.warning_threshold_percent', 80)
        ->assertJsonPath('data.week.tuesday.0.end', '17:00');
});

it('clears the cached settings when they are updated', function (): void {
    $repository = app(SlaSettingsRepository::class);
    expect($repository->current()->timezone)->toBe('Asia/Dhaka');

    app(UpdateSlaSettings::class)->handle(slaSettingsPayload());

    expect($repository->current()->timezone)->toBe('Europe/London')
        ->and($repository->current()->warningThresholdPercent)->toBe(80);

    app(UpdateSlaSettings::class)->handle(slaSettingsPayload(['timezone' => 'Asia/Tokyo']));

    expect($repository->current()->timezone)->toBe('Asia/Tokyo');
});

it('queues the active-ticket recalculation only when the admin asks for it', function (Closure $change, bool $queued): void {
    Queue::fake();
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);

    $change($holiday);

    $queued
        ? Queue::assertPushed(RecalculateActiveTicketSlas::class, 1)
        : Queue::assertNotPushed(RecalculateActiveTicketSlas::class);
})->with([
    'settings saved without the box' => [fn (Holiday $h) => app(UpdateSlaSettings::class)->handle(slaSettingsPayload()), false],
    'settings saved with the box' => [fn (Holiday $h) => app(UpdateSlaSettings::class)->handle(slaSettingsPayload(), true), true],
    'holiday added without the box' => [fn (Holiday $h) => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day']), false],
    'holiday added with the box' => [fn (Holiday $h) => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day', 'recalculate_active_tickets' => true]), true],
    'holiday changed with the box' => [fn (Holiday $h) => app(UpdateHoliday::class)->handle($h, ['date' => '2026-12-17', 'name' => 'Victory Day', 'recalculate_active_tickets' => '1']), true],
    'holiday deleted with the box' => [fn (Holiday $h) => app(DeleteHoliday::class)->handle($h, true), true],
]);

it('passes the recalculate box from the settings and holiday screens to the job', function (string $method, Closure $route, array $payload): void {
    Queue::fake();
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);

    $this->actingAs($this->admin)
        ->call($method, $route($holiday), [...$payload, 'recalculate_active_tickets' => true])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    Queue::assertPushed(RecalculateActiveTicketSlas::class, 1);
})->with([
    'settings page' => ['PUT', fn (Holiday $h) => route('admin.sla.update'), slaSettingsPayload()],
    'add holiday' => ['POST', fn (Holiday $h) => route('admin.holidays.store'), ['date' => '2026-02-21', 'name' => 'Language Day']],
    'edit holiday' => ['PATCH', fn (Holiday $h) => route('admin.holidays.update', $h), ['date' => '2026-12-17', 'name' => 'Victory Day']],
]);
