<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;

final readonly class DemoStep
{
    public function __construct(
        public DemoStepType $type,
        public int $afterMinutes,
        public ?string $who = null,
        public ?DemoFileType $file = null,
        public ?TicketStatus $status = null,
        public ?TicketPriority $priority = null,
        public ?string $category = null,
        public bool $outsideCategory = false,
        public DemoLine $line = DemoLine::Conversation,
    ) {}
}
