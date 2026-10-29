<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Holiday;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                ],
                'can' => $this->abilities($user),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'notifications' => [
                'unread_count' => fn (): int => $user?->unreadNotifications()->count() ?? 0,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array<string, bool> */
    private function abilities(?User $user): array
    {
        return [
            'viewDashboard' => $user?->can('viewDashboard') ?? false,
            'manageImports' => $user?->can('viewAny', TicketImport::class) ?? false,
            'manageUsers' => $user?->can('viewAny', User::class) ?? false,
            'manageCategories' => $user?->can('viewAny', Category::class) ?? false,
            'manageHolidays' => $user?->can('viewAny', Holiday::class) ?? false,
            'createOnBehalf' => $user?->can('createOnBehalf', Ticket::class) ?? false,
        ];
    }
}
