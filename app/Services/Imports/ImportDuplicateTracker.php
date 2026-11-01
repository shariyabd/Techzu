<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Ticket;
use App\Models\User;

final class ImportDuplicateTracker
{
    private const int FINGERPRINT_SCAN_CHUNK = 100;

    /** @var array<string, int> */
    private array $seen = [];

    /** @param array<string, string|null> $row */
    public function check(array $row, int $rowNumber, string $timezone): ?string
    {
        $key = self::key($row, $timezone);

        if ($key === null) {
            return null;
        }

        if (isset($this->seen[$key])) {
            return sprintf('Duplicate of row %d.', $this->seen[$key]);
        }

        return $this->existsInDatabase($row, $timezone) ? 'Duplicate of an existing ticket.' : null;
    }

    /** @param array<string, string|null> $row */
    public function record(array $row, int $rowNumber, string $timezone): void
    {
        $key = self::key($row, $timezone);

        if ($key !== null) {
            $this->seen[$key] ??= $rowNumber;
        }
    }

    /** @param array<string, string|null> $row */
    public static function fingerprint(array $row, string $timezone): ?string
    {
        $key = self::key($row, $timezone);

        return $key === null ? null : hash('sha256', $key);
    }

    private static function descriptionFingerprint(string $description): string
    {
        return hash('sha256', self::normalise($description));
    }

    /** @param array<string, string|null> $row */
    private function existsInDatabase(array $row, string $timezone): bool
    {
        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, $timezone);
        $tickets = Ticket::query()
            ->whereIn('customer_id', User::query()->where('email', trim((string) $row['customer_email']))->select('id'))
            ->where('subject', trim((string) $row['subject']));

        if ($createdAt !== null) {
            return $tickets->where('created_at', $createdAt)->exists();
        }

        $fingerprint = self::descriptionFingerprint((string) $row['description']);

        return $tickets
            ->select(['id', 'description'])
            ->lazyById(self::FINGERPRINT_SCAN_CHUNK)
            ->contains(static fn (Ticket $ticket): bool => self::descriptionFingerprint($ticket->description) === $fingerprint);
    }

    /** @param array<string, string|null> $row */
    private static function key(array $row, string $timezone): ?string
    {
        if (($row['external_reference'] ?? null) !== null) {
            return null;
        }

        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, $timezone);
        $customer = mb_strtolower(trim((string) $row['customer_email']));
        $subject = mb_strtolower(trim((string) $row['subject']));

        return $createdAt !== null
            ? implode('|', ['created', $customer, $subject, $createdAt->format('Y-m-d H:i:s')])
            : implode('|', ['fingerprint', $customer, $subject, self::descriptionFingerprint((string) $row['description'])]);
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
