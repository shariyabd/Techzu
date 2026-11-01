<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\TicketPriority;
use App\Events\SlaRulesChanged;
use App\Exceptions\Domain\InvalidSlaSettings;
use App\Models\BusinessHourWindow;
use App\Models\SlaSetting;
use App\Models\SlaTarget;
use App\Services\Sla\SlaSettings;
use App\Services\Sla\SlaSettingsRepository;
use App\Services\Sla\SlaSettingsRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class UpdateSlaSettings
{
    public function __construct(private readonly SlaSettingsRepository $settings) {}

    /**
     * @param  array{resolution_minutes: array<string, int>, warning_threshold_percent: int, timezone: string, week: array<string, list<array{start: string, end: string}>>}  $data
     *
     * @throws InvalidSlaSettings
     */
    public function handle(array $data, bool $recalculateActiveTickets = false): SlaSettings
    {
        $violations = SlaSettingsRules::violations($data);

        if ($violations !== []) {
            throw new InvalidSlaSettings((string) reset($violations));
        }

        DB::transaction(function () use ($data): void {
            $now = CarbonImmutable::now();

            SlaSetting::query()->upsert([[
                'id' => SlaSetting::SINGLETON_ID,
                'timezone' => $data['timezone'],
                'warning_threshold_percent' => $data['warning_threshold_percent'],
            ]], ['id'], ['timezone', 'warning_threshold_percent']);

            $targets = [];

            foreach (TicketPriority::cases() as $priority) {
                $targets[] = ['priority' => $priority->value, 'resolution_minutes' => $data['resolution_minutes'][$priority->value]];
            }

            SlaTarget::query()->upsert($targets, ['priority'], ['resolution_minutes']);

            BusinessHourWindow::query()->delete();

            $windows = [];

            foreach (SlaSettings::WEEKDAYS as $weekday) {
                foreach ($data['week'][$weekday] ?? [] as $window) {
                    $windows[] = [
                        'weekday' => $weekday,
                        'starts_at' => $window['start'],
                        'ends_at' => $window['end'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            BusinessHourWindow::query()->insert($windows);
        });

        $this->settings->flush();

        SlaRulesChanged::dispatch($recalculateActiveTickets);

        return $this->settings->current();
    }
}
