<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateUser;
use App\Actions\Admin\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListRequest;
use App\Http\Requests\Users\StoreRequest;
use App\Http\Requests\Users\UpdateRequest;
use App\Http\Resources\V1\AdminUserResource;
use App\Models\User;
use App\Services\Users\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function index(ListRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('admin/users/Index', [
            'users' => AdminUserResource::collection($this->directory->paginate($filters)),
            'filters' => ['q' => $filters['q'], 'role' => $filters['role']],
            'roles' => $this->directory->roleOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->directory->categoryOptions(),
        ]);
    }

    public function store(StoreRequest $request, CreateUser $createUser): RedirectResponse
    {
        $createUser->handle($this->admin($request), $request->userData());

        return to_route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => AdminUserResource::make($this->directory->load($user))->resolve($request),
            'roles' => $this->directory->roleOptions(),
            'categories' => $this->directory->categoryOptions(),
            'isSelf' => $this->admin($request)->is($user),
        ]);
    }

    public function update(UpdateRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $updateUser->handle($this->admin($request), $user, $request->userData());

        return to_route('admin.users.index')->with('success', 'User updated.');
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}
