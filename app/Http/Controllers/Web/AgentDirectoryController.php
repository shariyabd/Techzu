<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agents\ListRequest;
use App\Http\Requests\Agents\ShowRequest;
use App\Http\Resources\V1\AgentDirectoryResource;
use App\Http\Resources\V1\TicketListResource;
use App\Models\User;
use App\Services\Agents\AgentDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

final class AgentDirectoryController extends Controller
{
    public function __construct(private readonly AgentDirectory $directory) {}

    public function index(ListRequest $request): Response
    {
        $now = CarbonImmutable::now();

        return Inertia::render('agents/Index', [
            ...$this->listProps($request, $now),
            'summary' => fn (): array => $this->directory->summary($now),
        ]);
    }

    public function show(ShowRequest $request, User $user): Response
    {
        $viewer = $request->user();
        assert($viewer instanceof User);
        $now = CarbonImmutable::now();
        $tab = $request->tab();

        return Inertia::render('agents/Show', [
            ...$this->listProps($request, $now),
            'agent' => fn (): array => AgentDirectoryResource::make($this->directory->profile($user, $now))->resolve($request),
            'tab' => $tab,
            'tickets' => fn (): AnonymousResourceCollection => TicketListResource::collection($this->directory->tickets($viewer, $user, $tab, $now)),
        ]);
    }

    /** @return array<string, mixed> */
    private function listProps(ListRequest $request, CarbonImmutable $now): array
    {
        $filters = $request->filters();
        $pages = $this->directory->clampListPages($request->listPages());

        return [
            'agents' => function () use ($filters, $pages, $now): AnonymousResourceCollection {
                $list = $this->directory->browse($filters, $pages, $now);

                return AgentDirectoryResource::collection($list['agents'])->additional(['has_more' => $list['has_more']]);
            },
            'listPages' => $pages,
            'filters' => [
                'q' => $filters['q'],
                'role' => $filters['role'],
                'availability' => $filters['availability'],
                'category_id' => $filters['category_id'],
                'sort' => $filters['sort'],
            ],
            'options' => fn (): array => [
                'roles' => AgentDirectory::options(AgentDirectory::ROLE_FILTERS),
                'availability' => AgentDirectory::options(AgentDirectory::AVAILABILITY_FILTERS),
                'sorts' => AgentDirectory::options(AgentDirectory::SORTS),
                'categories' => $this->directory->categoryOptions(),
            ],
            'resolvedWindowDays' => $this->directory->resolvedWindowDays(),
        ];
    }
}
