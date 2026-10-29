<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Actions\Imports\StartTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;

final class ImportCsvFixture
{
    public static function mixed(): string
    {
        return implode("\n", [
            'external_reference,subject,description,priority,category,customer_email,status,created_at',
            'EXT-1,Printer on fire,The office printer is on fire,critical,hardware,customer@example.com,,2026-10-29 17:00',
            'EXT-2,=SUM(A1:A2),Formula in subject,urgent,hardware,customer@example.com,,',
            'EXT-1,Duplicate in file,Same reference as row 2,low,hardware,customer@example.com,,',
            'EXT-OLD,Already imported,Reference exists in the database,low,hardware,customer@example.com,,',
            ',Waiting on customer,No reference and no date,low,hardware,CUSTOMER@example.com,waiting_for_customer,',
            ',Unknown customer,Email has no account,medium,hardware,nobody@example.com,,',
            ',From the future,Created in 2030,medium,hardware,customer@example.com,,2030-01-01 10:00',
            ',Unknown category,Slug does not exist,high,plumbing,customer@example.com,,',
            ',Agent as customer,Email belongs to an agent,high,hardware,agent@example.com,,',
        ])."\n";
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    public static function row(array $overrides = []): array
    {
        return array_merge([
            'external_reference' => 'EXT-ROW',
            'subject' => 'Printer on fire',
            'description' => 'The office printer is on fire',
            'priority' => 'critical',
            'category' => 'hardware',
            'customer_email' => 'customer@example.com',
            'status' => null,
            'created_at' => '2026-10-29 17:00',
        ], $overrides);
    }

    public static function pendingImport(User $admin, string $path = 'imports/fixture.csv'): TicketImport
    {
        $import = new TicketImport(['original_name' => 'fixture.csv', 'path' => $path]);
        $import->user_id = $admin->id;
        $import->save();

        return $import->refresh();
    }

    public static function run(User $admin, string $csv, string $name = 'tickets.csv'): TicketImport
    {
        return app(StartTicketImport::class)
            ->handle($admin, UploadedFile::fake()->createWithContent($name, $csv))
            ->refresh();
    }
}
