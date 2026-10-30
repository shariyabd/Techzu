<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Ticket;
use App\Models\User;

final class ImportDuplicateTracker
{
    /** @var array<string, int> */
    private array $seen = [];

    /** @param array<string, string|null> $row */
    public function check(array $row, int $rowNumber, string $timezone): ?string
    {
        $key = $this->key($row, $timezone);

        if ($key === null) {
            return null;
        }

        if (isset($this->seen[$key])) {
            return sprintf('Duplicate of row %d.', $this->seen[$key]);
        }

        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, $timezone);

        $exists = Ticket::query()
            ->whereIn('customer_id', User::query()->where('email', (string) $row['customer_email'])->select('id'))
            ->where('created_at', $createdAt)
            ->where('subject', (string) $row['subject'])
            ->exists();

        return $exists ? 'Duplicate of an existing ticket.' : null;
    }

    /** @param array<string, string|null> $row */
    public function record(array $row, int $rowNumber, string $timezone): void
    {
        $key = $this->key($row, $timezone);

        if ($key !== null) {
            $this->seen[$key] ??= $rowNumber;
        }
    }

    /** @param array<string, string|null> $row */
    private function key(array $row, string $timezone): ?string
    {
        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, $timezone);

        if (($row['external_reference'] ?? null) !== null || $createdAt === null) {
            return null;
        }

        return implode('|', [mb_strtolower((string) $row['customer_email']), mb_strtolower((string) $row['subject']), $createdAt->format('Y-m-d H:i:s')]);
    }
}
