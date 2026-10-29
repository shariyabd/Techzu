<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DashboardResource;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): DashboardResource
    {
        $viewer = $request->user();
        assert($viewer instanceof User);

        return DashboardResource::make([
            'stats' => $stats->summary(),
            'my_tickets' => $stats->myTickets($viewer),
        ]);
    }
}
