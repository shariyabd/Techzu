<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateHoliday;
use App\Actions\Admin\DeleteHoliday;
use App\Actions\Admin\UpdateHoliday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexHolidaysRequest;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateHolidayRequest;
use App\Http\Resources\V1\HolidayResource;
use App\Models\Holiday;
use App\Services\Admin\AdminCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HolidayController extends Controller
{
    public function index(IndexHolidaysRequest $request, AdminCatalog $catalog): Response
    {
        $year = $request->year();

        return Inertia::render('admin/holidays/Index', [
            'year' => $year,
            'holidays' => HolidayResource::collection($catalog->holidays($year)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/holidays/Create');
    }

    public function store(StoreHolidayRequest $request, CreateHoliday $create): RedirectResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return $this->backToYear($create->handle($data), 'Holiday added. New SLA deadlines will skip it.');
    }

    public function edit(Request $request, Holiday $holiday): Response
    {
        return Inertia::render('admin/holidays/Edit', [
            'holiday' => HolidayResource::make($holiday)->resolve($request),
        ]);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday, UpdateHoliday $update): RedirectResponse
    {
        /** @var array{date: string, name: string} $data */
        $data = $request->validated();

        return $this->backToYear($update->handle($holiday, $data), 'Holiday updated.');
    }

    public function destroy(Holiday $holiday, DeleteHoliday $delete): RedirectResponse
    {
        $delete->handle($holiday);

        return $this->backToYear($holiday, 'Holiday deleted.');
    }

    private function backToYear(Holiday $holiday, string $message): RedirectResponse
    {
        return to_route('admin.holidays.index', ['year' => (int) $holiday->date->format('Y')])
            ->with('success', $message);
    }
}
