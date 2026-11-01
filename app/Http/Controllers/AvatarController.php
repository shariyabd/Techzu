<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Users\AvatarStorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class AvatarController extends Controller
{
    public function __invoke(Request $request, User $user, AvatarStorer $avatars): Response
    {
        Gate::authorize('viewAvatar', $user);

        $response = $avatars->response($user, $request);

        abort_if($response === null, 404);

        return $response;
    }
}
