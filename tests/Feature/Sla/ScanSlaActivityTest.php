<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

it('records one sla_approaching and one escalated entry, caused by the system', function (): void {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($now);
    $base = ['status' => TicketStatus::InProgress, 'sla_paused_at' => null, 'escalation_level' => EscalationLevel::None];
    $approaching = Ticket::factory()->create([...$base, 'sla_warning_at' => $now->subMinutes(10), 'sla_due_at' => $now->addMinutes(20)]);
    $breached = Ticket::factory()->create([...$base, 'sla_warning_at' => $now->subHours(2), 'sla_due_at' => $now->subHour()]);

    $this->artisan('sla:scan')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    $entries = fn (Ticket $ticket): array => $ticket->activities()
        ->where('log_name', 'ticket')
        ->get(['event', 'causer_id'])
        ->map(fn ($activity): array => [$activity->event, $activity->causer_id])
        ->all();
    expect($entries($approaching))->toBe([[ActivityType::SlaApproaching->value, null]])
        ->and($entries($breached))->toBe([[ActivityType::Escalated->value, null]]);
});
