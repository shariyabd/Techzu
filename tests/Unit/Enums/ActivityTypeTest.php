<?php

declare(strict_types=1);

use App\Enums\ActivityType;

it('describes activities as timeline lines', function (ActivityType $type, array $properties, string $line): void {
    expect($type->describe($properties))->toBe($line);
})->with([
    'created' => [ActivityType::Created, [], 'Ticket created'],
    'updated with fields' => [ActivityType::Updated, ['fields' => ['subject', 'description']], 'Ticket updated (subject, description)'],
    'updated without fields' => [ActivityType::Updated, [], 'Ticket updated'],
    'first assignment' => [ActivityType::Assigned, ['from' => null, 'to' => ['id' => 2, 'name' => 'Agent B']], 'Assigned to Agent B'],
    'reassignment' => [ActivityType::Assigned, ['from' => ['id' => 1, 'name' => 'Agent A'], 'to' => ['id' => 2, 'name' => 'Agent B']], 'Reassigned from Agent A to Agent B'],
    'claimed' => [ActivityType::Claimed, ['from' => null, 'to' => ['id' => 2, 'name' => 'Agent B']], 'Claimed by Agent B'],
    'unassigned' => [ActivityType::Unassigned, ['from' => ['id' => 1, 'name' => 'Agent A'], 'to' => null], 'Unassigned from Agent A'],
    'status changed' => [ActivityType::StatusChanged, ['from' => 'in_progress', 'to' => 'resolved'], 'Status changed from In Progress to Resolved'],
    'priority changed' => [ActivityType::PriorityChanged, ['from' => 'medium', 'to' => 'high'], 'Priority changed from Medium to High'],
    'category changed' => [ActivityType::CategoryChanged, ['from' => ['id' => 1, 'name' => 'Billing'], 'to' => ['id' => 2, 'name' => 'Technical Support']], 'Category changed from Billing to Technical Support'],
    'agent replied' => [ActivityType::Replied, ['author_is_staff' => true], 'Agent replied'],
    'customer replied' => [ActivityType::Replied, ['author_is_staff' => false], 'Customer replied'],
    'internal note' => [ActivityType::InternalNote, [], 'Internal note added'],
    'sla paused' => [ActivityType::SlaPaused, [], 'SLA paused while waiting for the customer'],
    'sla resumed' => [ActivityType::SlaResumed, [], 'SLA resumed'],
    'sla approaching' => [ActivityType::SlaApproaching, [], 'SLA breach approaching'],
    'escalated' => [ActivityType::Escalated, [], 'Ticket escalated: SLA breached'],
    'imported with row' => [ActivityType::Imported, ['row' => 12], 'Ticket imported from CSV (row 12)'],
    'imported without row' => [ActivityType::Imported, [], 'Ticket imported from CSV'],
]);

it('degrades gracefully when properties are missing or unknown', function (): void {
    expect(ActivityType::Assigned->describe([]))->toBe('Assigned to nobody')
        ->and(ActivityType::StatusChanged->describe(['from' => 'archived', 'to' => 'open']))->toBe('Status changed from archived to Open')
        ->and(ActivityType::PriorityChanged->describe([]))->toBe('Priority changed from unknown to unknown');
});

it('marks only internal notes as staff-only', function (ActivityType $type): void {
    expect($type->isStaffOnly())->toBe($type === ActivityType::InternalNote);
})->with(ActivityType::cases());
