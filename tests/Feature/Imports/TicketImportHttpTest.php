<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    Notification::fake();
    $this->withoutVite();
    Storage::fake((string) config('imports.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('rejects a non-CSV or oversized upload', function (Closure $file): void {
    $this->actingAs($this->admin)->post(route('admin.imports.store'), ['file' => $file()])->assertSessionHasErrors('file');

    expect(TicketImport::query()->count())->toBe(0);
})->with([
    'a PDF' => [fn (): UploadedFile => UploadedFile::fake()->create('tickets.pdf', 10, 'application/pdf')],
    'larger than 50 MB' => [fn (): UploadedFile => UploadedFile::fake()->create('tickets.csv', 51201, 'text/csv')],
]);

it('stores the upload privately under a random name and redirects to the import', function (): void {
    $response = $this->actingAs($this->admin)->post(route('admin.imports.store'), [
        'file' => UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed()),
    ]);

    $import = TicketImport::query()->sole();
    $response->assertRedirect(route('admin.imports.show', $import));
    expect($import->original_name)->toBe('tickets.csv')
        ->and($import->path)->toStartWith('imports/')
        ->and($import->path)->not->toContain('tickets.csv');
    Storage::disk((string) config('imports.disk'))->assertExists($import->path);
});

it('accepts an API upload with 202 and the import resource', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson(route('api.v1.imports.store'), ['file' => UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed())])
        ->assertStatus(202)
        ->assertJsonPath('data.status.value', 'completed_with_errors')
        ->assertJsonPath('data.status.label', 'Completed with errors')
        ->assertJsonPath('data.total_rows', 9)
        ->assertJsonPath('data.failed_rows', 7)
        ->assertJsonPath('data.progress_percent', 100)
        ->assertJsonPath('data.is_finished', true)
        ->assertJsonPath('data.user.id', $this->admin->id);
});

it('renders the index, create and show props', function (): void {
    $older = ImportCsvFixture::run($this->admin, "subject,description,priority,category,customer_email\nPrinter,Broken,low,hardware,customer@example.com\n", 'first.csv');
    $newer = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed(), 'second.csv');

    $this->actingAs($this->admin)->get(route('admin.imports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Index', false)
            ->where('imports.data.0.id', $newer->id)
            ->where('imports.data.1.id', $older->id)
            ->where('imports.meta.total', 2));
    $this->actingAs($this->admin)->get(route('admin.imports.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Create', false)
            ->where('maxUploadKb', 51200)
            ->where('columns.required', ['subject', 'description', 'priority', 'category', 'customer_email']));
    $this->actingAs($this->admin)->get(route('admin.imports.show', $newer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Show', false)
            ->where('ticketImport.id', $newer->id)
            ->where('ticketImport.processed_rows', 9)
            ->where('importErrors.data.0.row_number', 3)
            ->where('importErrors.meta.total', 7));
});

it('shows an import and pages its errors over the API, capping per_page at 100', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.imports.show', $import))->assertOk()->assertJsonPath('data.id', $import->id);
    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 2]))
        ->assertOk()
        ->assertJsonPath('data.0.row_number', 3)
        ->assertJsonPath('data.1.row_number', 4)
        ->assertJsonPath('meta.total', 7);
    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('streams the errors as a CSV that neutralises spreadsheet formulas', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $response = $this->actingAs($this->admin)->get(route('admin.imports.errors.download', $import));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Content-Disposition'))->toContain(sprintf('attachment; filename=import-%d-errors.csv', $import->id));
    $lines = explode("\n", trim($response->streamedContent()));
    expect($lines)->toHaveCount(8)
        ->and($lines[0])->toBe('row_number,errors,external_reference,subject,description,priority,category,customer_email,status,created_at')
        ->and($lines[1])->toStartWith('3,"The selected priority is invalid.",EXT-2,\'=SUM(A1:A2),');
});
