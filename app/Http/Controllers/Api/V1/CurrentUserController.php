<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    public function __invoke(Request $request): CurrentUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CurrentUserResource($user);
    }
}
