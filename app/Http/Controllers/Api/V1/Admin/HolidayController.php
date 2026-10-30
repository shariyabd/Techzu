<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Holidays\ListRequest;
use App\Http\Requests\Holidays\StoreRequest;
use App\Http\Requests\Holidays\UpdateRequest;
use App\Http\Resources\V1\HolidayResource;
use App\Models\Holiday;
use App\Services\Admin\AdminCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class HolidayController extends Controller
{
    public function index(ListRequest $request, AdminCatalog $catalog): AnonymousResourceCollection
    {
        return HolidayResource::collection($catalog->holidays($request->year()));
    }

    public function store(StoreRequest $request, CreateHoliday $create): JsonResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return HolidayResource::make($create->handle($data))->response()->setStatusCode(201);
    }

    public function update(UpdateRequest $request, Holiday $holiday, UpdateHoliday $update): HolidayResource
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return HolidayResource::make($update->handle($holiday, $data));
    }

    public function destroy(Holiday $holiday, DeleteHoliday $delete): Response
    {
        $delete->handle($holiday);

        return response()->noContent();
    }
}
