<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListRequest;
use App\Http\Requests\Users\StoreRequest;
use App\Http\Requests\Users\UpdateRequest;
use App\Http\Resources\V1\AdminUserResource;
use App\Models\User;
use App\Services\Users\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        return AdminUserResource::collection($this->directory->paginate($request->filters()));
    }

    public function store(StoreRequest $request, CreateUser $createUser): JsonResponse
    {
        $user = $createUser->handle($this->admin($request), $request->userData());

        return AdminUserResource::make($this->directory->load($user))->response()->setStatusCode(201);
    }

    public function update(UpdateRequest $request, User $user, UpdateUser $updateUser): AdminUserResource
    {
        return AdminUserResource::make(
            $this->directory->load($updateUser->handle($this->admin($request), $user, $request->userData())),
        );
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}
