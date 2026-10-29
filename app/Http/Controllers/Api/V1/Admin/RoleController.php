<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RoleResource;
use App\Services\Users\UserDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleController extends Controller
{
    public function __invoke(UserDirectory $directory): AnonymousResourceCollection
    {
        return RoleResource::collection($directory->rolesWithPermissions());
    }
}
