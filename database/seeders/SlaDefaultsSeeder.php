<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Admin\UpdateSlaSettings;
use App\Services\Sla\SlaSettingsRepository;
use Illuminate\Database\Seeder;

final class SlaDefaultsSeeder extends Seeder
{
    public function run(SlaSettingsRepository $settings, UpdateSlaSettings $update): void
    {
        $settings->flush();

        if ($settings->current()->stored) {
            return;
        }

        $update->handle($settings->defaults()->toInput());
    }
}
