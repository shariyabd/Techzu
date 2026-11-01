<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agents\ListRequest;
use App\Http\Resources\V1\AgentDirectoryResource;
use App\Models\User;
use App\Services\Agents\AgentDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AgentDirectoryController extends Controller
{
    public function __construct(private readonly AgentDirectory $directory) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        return AgentDirectoryResource::collection($this->directory->paginate($request->filters(), $now))
            ->additional(['summary' => $this->directory->summary($now)]);
    }

    public function show(User $user): AgentDirectoryResource
    {
        return AgentDirectoryResource::make($this->directory->profile($user, CarbonImmutable::now()));
    }
}
