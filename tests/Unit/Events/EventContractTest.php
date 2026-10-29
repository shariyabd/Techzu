<?php

declare(strict_types=1);

use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketImportFinished;
use App\Events\TicketPriorityChanged;
use App\Events\TicketReplied;
use App\Events\TicketResolved;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Events\TicketStatusChanged;
use App\Events\TicketUpdated;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

dataset('domain events', [
    TicketCreated::class => [TicketCreated::class, ['ticket', 'actor', 'imported']],
    TicketUpdated::class => [TicketUpdated::class, ['ticket', 'actor', 'changes']],
    TicketAssigned::class => [TicketAssigned::class, ['ticket', 'actor', 'agent', 'previousAgent', 'method']],
    TicketStatusChanged::class => [TicketStatusChanged::class, ['ticket', 'actor', 'from', 'to']],
    TicketResolved::class => [TicketResolved::class, ['ticket', 'actor']],
    TicketPriorityChanged::class => [TicketPriorityChanged::class, ['ticket', 'actor', 'from', 'to']],
    TicketReplied::class => [TicketReplied::class, ['ticket', 'reply', 'author']],
    TicketSlaApproaching::class => [TicketSlaApproaching::class, ['ticket']],
    TicketSlaBreached::class => [TicketSlaBreached::class, ['ticket']],
    TicketImportFinished::class => [TicketImportFinished::class, ['import']],
]);

it('follows the event contract', function (string $class, array $params): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(class_uses($class))->toHaveKeys([Dispatchable::class, SerializesModels::class]);

    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $constructor->getParameters());
    expect($names)->toBe($params);

    foreach ($params as $name) {
        $property = $reflection->getProperty($name);
        expect($property->isPublic())->toBeTrue()
            ->and($property->isReadOnly())->toBeTrue();
    }
})->with('domain events');
