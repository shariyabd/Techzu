<?php

declare(strict_types=1);

namespace App\Http\Requests\SlaSettings;

use App\Enums\TicketPriority;
use App\Services\Sla\SlaSettings;
use App\Services\Sla\SlaSettingsRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [
            'resolution_minutes' => ['required', 'array:'.implode(',', array_column(TicketPriority::cases(), 'value'))],
            'warning_threshold_percent' => ['required', 'integer', 'between:'.SlaSettingsRules::MIN_THRESHOLD_PERCENT.','.SlaSettingsRules::MAX_THRESHOLD_PERCENT],
            'timezone' => ['required', 'string', 'timezone:all'],
            'week' => ['required', 'array:'.implode(',', SlaSettings::WEEKDAYS)],
            'week.*' => ['array', 'max:'.SlaSettingsRules::MAX_WINDOWS_PER_DAY],
            'week.*.*' => ['array:start,end'],
            'week.*.*.start' => ['required', 'string', 'regex:'.SlaSettingsRules::TIME_PATTERN],
            'week.*.*.end' => ['required', 'string', 'regex:'.SlaSettingsRules::TIME_PATTERN],
            'recalculate_active_tickets' => ['sometimes', 'boolean'],
        ];

        foreach (TicketPriority::cases() as $priority) {
            $rules["resolution_minutes.{$priority->value}"] = ['required', 'integer', 'between:'.SlaSettingsRules::MIN_TARGET_MINUTES.','.SlaSettingsRules::MAX_TARGET_MINUTES];
        }

        return $rules;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $week = $this->input('week');

                if (! is_array($week)) {
                    return;
                }

                foreach (SlaSettingsRules::weekViolations($week) as $field => $message) {
                    if (! $validator->errors()->has($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /** @return array{resolution_minutes: array<string, int>, warning_threshold_percent: int, timezone: string, week: array<string, list<array{start: string, end: string}>>} */
    public function settings(): array
    {
        /** @var array<string, int|string> $minutes */
        $minutes = $this->validated('resolution_minutes');

        /** @var array<string, array<array-key, array{start: string, end: string}>> $week */
        $week = $this->validated('week');

        $normalizedWeek = [];

        foreach (SlaSettings::WEEKDAYS as $weekday) {
            $normalizedWeek[$weekday] = array_values(array_map(
                static fn (array $window): array => ['start' => (string) $window['start'], 'end' => (string) $window['end']],
                $week[$weekday] ?? [],
            ));
        }

        return [
            'resolution_minutes' => array_map(static fn (int|string $value): int => (int) $value, $minutes),
            'warning_threshold_percent' => $this->integer('warning_threshold_percent'),
            'timezone' => (string) $this->validated('timezone'),
            'week' => $normalizedWeek,
        ];
    }

    public function recalculateActiveTickets(): bool
    {
        return $this->boolean('recalculate_active_tickets');
    }
}
