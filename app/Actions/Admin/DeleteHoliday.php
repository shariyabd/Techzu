<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Events\SlaRulesChanged;
use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;

final class DeleteHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}

    public function handle(Holiday $holiday, bool $recalculateActiveTickets = false): void
    {
        $holiday->delete();
        $this->holidays->flush();

        SlaRulesChanged::dispatch($recalculateActiveTickets);
    }
}
