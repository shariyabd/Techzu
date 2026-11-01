<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use App\Services\Sla\SlaSettingsRepository;
use Carbon\CarbonImmutable;

final class ImportSampleCsvWriter
{
    private const FALLBACK_CATEGORY = 'general-inquiry';

    private const FALLBACK_CUSTOMER = 'customer@example.com';

    private const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(private readonly SlaSettingsRepository $settings) {}

    /** @param resource $stream */
    public function write($stream, CarbonImmutable $now): void
    {
        $categories = $this->activeCategorySlugs();
        $customers = $this->customerEmails();
        $local = $now->setTimezone($this->settings->timezone());

        fputcsv($stream, TicketCsvReader::COLUMNS, ',', '"', '');

        foreach ($this->rows($local) as $index => $row) {
            $row['category'] = $categories[$index % count($categories)];
            $row['customer_email'] = $customers[$index % count($customers)];

            fputcsv($stream, array_map(
                static fn (string $column): string => $row[$column] ?? '',
                TicketCsvReader::COLUMNS,
            ), ',', '"', '');
        }
    }

    /** @return list<array<string, string>> */
    private function rows(CarbonImmutable $local): array
    {
        return [
            [
                'external_reference' => 'SAMPLE-001',
                'subject' => 'Card payment declined at checkout',
                'description' => 'Our company card is declined when we renew, but the bank says nothing was blocked.',
                'priority' => 'high',
                'status' => 'open',
                'created_at' => $local->subDays(2)->setTime(10, 0)->format(self::DATE_FORMAT),
            ],
            [
                'external_reference' => 'SAMPLE-002',
                'subject' => 'Monthly export is missing a column',
                'description' => 'The monthly export no longer includes the cost centre column.',
                'priority' => 'medium',
                'status' => 'waiting_for_customer',
                'created_at' => $local->subDay()->setTime(14, 30)->format(self::DATE_FORMAT),
            ],
            [
                'subject' => 'Add a second administrator to our account',
                'description' => 'Optional columns may be left empty: no reference, status Open, created now.',
                'priority' => 'low',
            ],
        ];
    }

    /** @return non-empty-list<string> */
    private function activeCategorySlugs(): array
    {
        $slugs = Category::query()->active()->orderBy('name')->limit(3)->pluck('slug')->all();

        return $slugs === [] ? [self::FALLBACK_CATEGORY] : array_values($slugs);
    }

    /** @return non-empty-list<string> */
    private function customerEmails(): array
    {
        $emails = User::query()->role(Role::Customer->value)->orderBy('users.id')->limit(3)->pluck('email')->all();

        return $emails === [] ? [self::FALLBACK_CUSTOMER] : array_values($emails);
    }
}
