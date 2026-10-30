<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Imports\StartTicketImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\ListRequest;
use App\Http\Requests\Imports\StoreRequest;
use App\Http\Resources\V1\TicketImportErrorResource;
use App\Http\Resources\V1\TicketImportResource;
use App\Models\TicketImport;
use App\Models\User;
use App\Services\Imports\TicketImportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TicketImportController extends Controller
{
    public function store(StoreRequest $request, StartTicketImport $start): JsonResponse
    {
        $admin = $request->user();
        assert($admin instanceof User);
        $import = $start->handle($admin, $request->csv());

        return TicketImportResource::make($import->load('user:id,name'))->response()->setStatusCode(202);
    }

    public function show(TicketImport $import): TicketImportResource
    {
        return TicketImportResource::make($import->load('user:id,name'));
    }

    public function errors(ListRequest $request, TicketImport $import, TicketImportQuery $imports): AnonymousResourceCollection
    {
        return TicketImportErrorResource::collection($imports->errors($import, $request->perPage()));
    }
}
