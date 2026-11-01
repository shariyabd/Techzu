<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function lastSeenOf(User $user): ?string
{
    $value = DB::table('users')->where('id', $user->id)->value('last_seen_at');

    return $value === null ? null : (string) $value;
}

it('records last seen at most once a minute per user', function (string $surface): void {
    $customer = User::factory()->customer()->create();
    $visit = function () use ($customer, $surface): void {
        if ($surface === 'web') {
            test()->actingAs($customer)->get('/tickets')->assertOk();

            return;
        }

        Sanctum::actingAs($customer);
        test()->getJson('/api/v1/tickets')->assertOk();
    };

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    $visit();
    $firstSeen = lastSeenOf($customer);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:40'));
    $visit();

    expect($firstSeen)->toStartWith('2026-11-02 10:00:00')
        ->and(lastSeenOf($customer))->toBe($firstSeen);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:01:05'));
    $visit();

    expect(lastSeenOf($customer))->toStartWith('2026-11-02 10:01:05');
})->with(['web', 'api']);

it('does not record last seen for guests', function (): void {
    $customer = User::factory()->customer()->create();

    $this->get('/login')->assertOk();

    expect(lastSeenOf($customer))->toBeNull();
});
