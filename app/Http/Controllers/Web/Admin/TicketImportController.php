<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Imports\StartTicketImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreTicketImportRequest;
use App\Http\Resources\V1\TicketImportErrorResource;
use App\Http\Resources\V1\TicketImportResource;
use App\Models\TicketImport;
use App\Models\User;
use App\Services\Imports\ImportErrorCsvWriter;
use App\Services\Imports\TicketCsvReader;
use App\Services\Imports\TicketImportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketImportController extends Controller
{
    public function __construct(private readonly TicketImportQuery $imports) {}

    public function index(): Response
    {
        return Inertia::render('admin/imports/Index', [
            'imports' => TicketImportResource::collection($this->imports->paginate(20)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/imports/Create', [
            'maxUploadKb' => (int) config('imports.max_upload_kb'),
            'columns' => [
                'required' => TicketCsvReader::REQUIRED_COLUMNS,
                'optional' => array_values(array_diff(TicketCsvReader::COLUMNS, TicketCsvReader::REQUIRED_COLUMNS)),
            ],
        ]);
    }

    public function store(StoreTicketImportRequest $request, StartTicketImport $start): RedirectResponse
    {
        $admin = $request->user();
        assert($admin instanceof User);
        $import = $start->handle($admin, $request->csv());

        return to_route('admin.imports.show', $import)->with('success', 'Import started. Progress updates on this page.');
    }

    public function show(Request $request, TicketImport $import): Response
    {
        return Inertia::render('admin/imports/Show', [
            'ticketImport' => fn (): mixed => TicketImportResource::make($import->load('user:id,name'))->toResponse($request)->getData(true)['data'],
            'importErrors' => fn (): AnonymousResourceCollection => TicketImportErrorResource::collection($this->imports->errors($import, 25)),
        ]);
    }

    public function downloadErrors(TicketImport $import, ImportErrorCsvWriter $writer): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($import, $writer): void {
                $stream = fopen('php://output', 'wb');
                $writer->write($import, $stream);
                fclose($stream);
            },
            sprintf('import-%d-errors.csv', $import->id),
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
