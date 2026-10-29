<?php

declare(strict_types=1);

namespace App\Services\Sla;

use Carbon\CarbonImmutable;

final readonly class SlaDeadlines
{
    public function __construct(
        public CarbonImmutable $dueAt,
        public CarbonImmutable $warningAt,
    ) {}
}
