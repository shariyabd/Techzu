<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class TrackLastSeen
{
    private const int THROTTLE_SECONDS = 60;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User && Cache::add("users.{$user->id}.last-seen", true, self::THROTTLE_SECONDS)) {
            User::query()->whereKey($user->id)->toBase()->update(['last_seen_at' => now()]);
        }

        return $response;
    }
}
