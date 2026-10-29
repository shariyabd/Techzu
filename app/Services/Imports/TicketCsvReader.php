<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Exceptions\Domain\ImportHeaderInvalid;
use Illuminate\Support\LazyCollection;
use SplFileObject;

final class TicketCsvReader
{
    public const COLUMNS = ['external_reference', 'subject', 'description', 'priority', 'category', 'customer_email', 'status', 'created_at'];

    public const REQUIRED_COLUMNS = ['subject', 'description', 'priority', 'category', 'customer_email'];

    /**
     * @return list<string>
     *
     * @throws ImportHeaderInvalid
     */
    public function header(string $path): array
    {
        $raw = $this->open($path)->current();

        if (! is_array($raw) || $this->isBlank($raw)) {
            throw new ImportHeaderInvalid('The CSV file is empty or has no header row.');
        }

        $raw = array_values($raw);
        $raw[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw[0]);
        $columns = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $raw);
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        $unknown = array_values(array_unique(array_diff($columns, self::COLUMNS)));
        $duplicates = array_values(array_unique(array_diff_assoc($columns, array_unique($columns))));
        $problems = array_filter([
            $missing === [] ? null : 'missing required columns: '.implode(', ', $missing),
            $unknown === [] ? null : 'unknown columns: '.implode(', ', array_map(static fn (string $c): string => $c === '' ? '(blank)' : $c, $unknown)),
            $duplicates === [] ? null : 'duplicate columns: '.implode(', ', $duplicates),
        ]);

        if ($problems !== []) {
            throw new ImportHeaderInvalid('The CSV header is invalid: '.implode('; ', $problems).'.');
        }

        return $columns;
    }

    /** @return array{rows: int, last_line: int} */
    public function scan(string $path): array
    {
        $rows = 0;
        $lastLine = 0;

        foreach ($this->records($path, 1, PHP_INT_MAX) as $line => $values) {
            $rows++;
            $lastLine = $line;
        }

        return ['rows' => $rows, 'last_line' => $lastLine];
    }

    /** @return LazyCollection<int, list<string|null>> */
    public function records(string $path, int $startLine, int $endLine): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $startLine, $endLine) {
            $file = $this->open($path);
            $file->seek($startLine);

            while ($file->valid() && $file->key() <= $endLine) {
                $values = $file->current();

                if (is_array($values) && ! $this->isBlank($values)) {
                    yield $file->key() => array_values(array_map(
                        static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                        $values,
                    ));
                }

                $file->next();
            }
        });
    }

    private function open(string $path): SplFileObject
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV);
        $file->setCsvControl(',', '"', '');

        return $file;
    }

    /** @param array<int, mixed> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
