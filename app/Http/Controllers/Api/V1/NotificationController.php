<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class NotificationController extends Controller
{
    public function index(ListRequest $request, NotificationFeed $feed): AnonymousResourceCollection
    {
        return NotificationResource::collection($feed->for($this->user($request), $request->perPage(), $request->unreadOnly()));
    }

    public function read(Request $request, string $notification, MarkNotificationRead $markRead): NotificationResource
    {
        return NotificationResource::make($markRead->handle($this->user($request), $notification));
    }

    public function readAll(Request $request, MarkAllNotificationsRead $markAllRead): Response
    {
        $markAllRead->handle($this->user($request));

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
