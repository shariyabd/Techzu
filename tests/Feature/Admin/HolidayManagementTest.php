<?php

declare(strict_types=1);

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Enums\TicketPriority;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Sla\BusinessCalendar;
use App\Services\Sla\HolidayRepository;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

it('flushes the cached holiday list after every change', function (Closure $change): void {
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
    app(HolidayRepository::class)->dates();
    $change($holiday);
    expect(Cache::has('sla.holidays'))->toBeFalse();
})->with([
    'create' => [fn (Holiday $h) => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day'])],
    'update' => [fn (Holiday $h) => app(UpdateHoliday::class)->handle($h, ['date' => '2026-12-17', 'name' => 'Victory Day'])],
    'delete' => [fn (Holiday $h) => app(DeleteHoliday::class)->handle($h)],
]);

it('makes new SLA deadlines skip a holiday added through the admin screen', function (): void {
    $start = CarbonImmutable::parse('2026-10-29 17:00', 'Asia/Dhaka');
    app(HolidayRepository::class)->dates();
    expect(app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start)->dueAt->toDateTimeString())->toBe('2026-11-01 04:00:00');
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-11-01', 'name' => 'Office closed'])
        ->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    app()->forgetInstance(SlaCalculator::class);
    app()->forgetInstance(BusinessCalendar::class);
    expect(app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start)->dueAt->toDateTimeString())->toBe('2026-11-02 04:00:00');
});

it('lists the holidays of the requested year, defaulting to the current one', function (): void {
    Holiday::factory()->create(['date' => '2026-12-16']);
    Holiday::factory()->create(['date' => '2026-02-21']);
    Holiday::factory()->create(['date' => '2027-02-21']);
    $this->actingAs($this->admin)->get(route('admin.holidays.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/holidays/Index', false)
            ->where('year', 2026)
            ->has('holidays.data', 2)
            ->where('holidays.data.0.date', '2026-02-21')
            ->where('holidays.data.0.weekday', 'Saturday'));
    $this->actingAs($this->admin)->get(route('admin.holidays.index', ['year' => 2027]))
        ->assertInertia(fn (Assert $page) => $page->where('year', 2027)->has('holidays.data', 1));
});

it('creates, updates and deletes a holiday, returning to its year', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
        ->assertSessionHas('success');
    $holiday = Holiday::query()->sole();
    $this->actingAs($this->admin)
        ->patch(route('admin.holidays.update', $holiday), ['date' => '2026-12-16', 'name' => 'Bijoy Dibos'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('admin.holidays.destroy', $holiday))
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]));
    expect(Holiday::query()->count())->toBe(0);
});

it('rejects a duplicate or malformed date', function (string $date): void {
    Holiday::factory()->create(['date' => '2026-12-16']);
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => $date, 'name' => 'Something'])
        ->assertSessionHasErrors('date');
})->with(['duplicate' => '2026-12-16', 'not Y-m-d' => '16/12/2026']);

it('manages holidays over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $id = $this->postJson(route('api.v1.admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertCreated()
        ->assertJsonPath('data.weekday', 'Wednesday')
        ->json('data.id');
    $this->getJson(route('api.v1.admin.holidays.index', ['year' => 2026]))->assertOk()->assertJsonPath('data.0.id', $id);
    $this->patchJson(route('api.v1.admin.holidays.update', $id), ['date' => '2026-12-17', 'name' => 'Victory Day'])
        ->assertOk()
        ->assertJsonPath('data.date', '2026-12-17');
    $this->deleteJson(route('api.v1.admin.holidays.destroy', $id))->assertNoContent();
});
