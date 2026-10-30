<?php

declare(strict_types=1);

use App\Exceptions\Domain\ImportHeaderInvalid;
use App\Services\Imports\TicketCsvReader;

function csvFixturePath(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tktcsv');
    file_put_contents($path, $contents);

    return $path;
}

function multiLineCsv(): string
{
    return implode("\n", [
        'subject,description,priority,category,customer_email',
        "A,\"line one\nline two\",low,general,a@example.com",
        'B,desc,low,general,b@example.com',
        '',
        'C,desc,low,general,c@example.com',
        'D,desc,low,general,d@example.com',
    ])."\n";
}

it('normalises the header: BOM, case, surrounding spaces and column order', function (): void {
    $path = csvFixturePath("\xEF\xBB\xBF Subject ,DESCRIPTION,priority,Category,customer_email,created_at\n");

    expect((new TicketCsvReader)->header($path))
        ->toBe(['subject', 'description', 'priority', 'category', 'customer_email', 'created_at']);
});

it('rejects an invalid header with a precise message', function (string $contents, string $message): void {
    expect(fn () => (new TicketCsvReader)->header(csvFixturePath($contents)))
        ->toThrow(ImportHeaderInvalid::class, $message);
})->with([
    'missing columns' => ["subject,description,priority\n", 'The CSV header is invalid: missing required columns: category, customer_email.'],
    'unknown and duplicate columns' => ["subject,description,priority,category,customer_email,colour,subject\n", 'The CSV header is invalid: unknown columns: colour; duplicate columns: subject.'],
    'empty file' => ['', 'The CSV file is empty or has no header row.'],
]);

it('counts records, skipping blank lines and keeping a quoted multi-line field as one record', function (): void {
    expect((new TicketCsvReader)->scan(csvFixturePath(multiLineCsv()), 500))->toMatchArray(['rows' => 4, 'last_line' => 5]);
});

it('starts each chunk at its stored byte offset and keeps record indexes, including after a multi-line field', function (): void {
    $path = csvFixturePath(multiLineCsv());
    $reader = new TicketCsvReader;
    $chunks = $reader->scan($path, 2)['chunks'];

    expect(array_column($chunks, 'start_line'))->toBe([1, 3, 5])
        ->and(array_column($chunks, 'end_line'))->toBe([2, 4, 5]);

    $records = [];
    foreach ($chunks as $chunk) {
        $records += $reader->records($path, $chunk['start_line'], $chunk['end_line'], $chunk['offset'])->all();
    }

    expect(array_keys($records))->toBe([1, 2, 4, 5])
        ->and($records[1][1])->toBe("line one\nline two")
        ->and($records[4][0])->toBe('C')
        ->and($records[5][0])->toBe('D');
});
