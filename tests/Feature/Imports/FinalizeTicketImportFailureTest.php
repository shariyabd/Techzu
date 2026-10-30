<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Events\TicketImportFinished;
use App\Jobs\FinalizeTicketImport;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Support\Imports\ImportCsvFixture;

it('does not leave an import processing when finalization exhausts its retries', function (): void {
    Event::fake([TicketImportFinished::class]);
    $import = ImportCsvFixture::pendingImport(User::factory()->admin()->create());
    $import->status = ImportStatus::Processing;
    $import->save();

    (new FinalizeTicketImport($import->id))->failed(new RuntimeException('boom'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull();
    Event::assertDispatched(TicketImportFinished::class);
});
