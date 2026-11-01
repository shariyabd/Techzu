<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\UpdateSlaSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\SlaSettings\UpdateRequest;
use App\Http\Resources\V1\SlaSettingsResource;
use App\Services\Sla\SlaSettingsRepository;

final class SlaSettingsController extends Controller
{
    public function show(SlaSettingsRepository $settings): SlaSettingsResource
    {
        return SlaSettingsResource::make($settings->current());
    }

    public function update(UpdateRequest $request, UpdateSlaSettings $update): SlaSettingsResource
    {
        return SlaSettingsResource::make($update->handle($request->settings(), $request->recalculateActiveTickets()));
    }
}
