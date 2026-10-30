<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(ListRequest $request, NotificationFeed $feed): Response
    {
        return Inertia::render('notifications/Index', [
            'items' => NotificationResource::collection($feed->for($this->user($request), $request->perPage(), $request->unreadOnly())),
            'unreadOnly' => $request->unreadOnly(),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationRead $markRead): RedirectResponse
    {
        $markRead->handle($this->user($request), $notification);

        return back();
    }

    public function readAll(Request $request, MarkAllNotificationsRead $markAllRead): RedirectResponse
    {
        $count = $markAllRead->handle($this->user($request));

        return back()->with('success', trans_choice('{1} :count notification marked as read.|[0,*] :count notifications marked as read.', $count, ['count' => $count]));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
