<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Holiday;
use App\Services\Sla\HolidayRepository;

final class UpdateHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}

    /** @param array{date: string, name: string} $data */
    public function handle(Holiday $holiday, array $data): Holiday
    {
        $holiday->update(['date' => $data['date'], 'name' => $data['name']]);
        $this->holidays->flush();

        return $holiday->refresh();
    }
}
