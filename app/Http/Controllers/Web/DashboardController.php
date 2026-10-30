<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TicketListResource;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): Response
    {
        $viewer = $request->user();
        assert($viewer instanceof User);

        return Inertia::render('dashboard/Index', [
            'stats' => fn (): array => $stats->summary(),
            'resolvedWindowDays' => (int) config('tickets.dashboard.resolved_window_days'),
            'myTickets' => fn (): array => TicketListResource::collection($stats->myTickets($viewer))->resolve($request),
        ]);
    }
}
