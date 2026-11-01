<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\UpdateSlaSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\SlaSettings\UpdateRequest;
use App\Http\Resources\V1\SlaSettingsResource;
use App\Services\Admin\AdminCatalog;
use App\Services\Sla\SlaSettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SlaSettingsController extends Controller
{
    public function edit(Request $request, SlaSettingsRepository $settings, AdminCatalog $catalog): Response
    {
        return Inertia::render('admin/sla/Edit', [
            'settings' => SlaSettingsResource::make($settings->current())->resolve($request),
            'timezones' => $catalog->timezones(),
        ]);
    }

    public function update(UpdateRequest $request, UpdateSlaSettings $update): RedirectResponse
    {
        $recalculate = $request->recalculateActiveTickets();
        $update->handle($request->settings(), $recalculate);

        return to_route('admin.sla.edit')->with('success', $recalculate
            ? 'SLA settings saved. Active tickets are being recalculated in the background.'
            : 'SLA settings saved. New tickets use them now. Active tickets keep their deadlines until their priority changes (recalculated with the new settings) or their paused clock restarts (remaining time counted in the new business hours).');
    }
}
