<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\TicketImport;
use Illuminate\Support\Arr;

final class ImportErrorCsvWriter
{
    public function __construct(private readonly TicketImportQuery $imports) {}

    /** @param resource $stream */
    public function write(TicketImport $import, $stream): void
    {
        fputcsv($stream, ['row_number', 'errors', ...TicketCsvReader::COLUMNS], ',', '"', '');

        foreach ($this->imports->allErrors($import) as $error) {
            $line = [(string) $error->row_number, implode(' | ', Arr::flatten($error->errors))];

            foreach (TicketCsvReader::COLUMNS as $column) {
                $line[] = (string) ($error->raw[$column] ?? '');
            }

            fputcsv($stream, array_map(self::cell(...), $line), ',', '"', '');
        }
    }

    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
