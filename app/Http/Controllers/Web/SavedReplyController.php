<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\SavedReplies\CreateSavedReply;
use App\Actions\SavedReplies\DeleteSavedReply;
use App\Actions\SavedReplies\UpdateSavedReply;
use App\Enums\SavedReplyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavedReplies\ListRequest;
use App\Http\Requests\SavedReplies\PickerRequest;
use App\Http\Requests\SavedReplies\StoreRequest;
use App\Http\Requests\SavedReplies\UpdateRequest;
use App\Http\Resources\V1\SavedReplyResource;
use App\Models\SavedReply;
use App\Models\User;
use App\Services\SavedReplies\SavedReplyQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

final class SavedReplyController extends Controller
{
    public function __construct(private readonly SavedReplyQuery $query) {}

    public function index(ListRequest $request): Response
    {
        $user = $this->user($request);
        $filters = $request->filters(SavedReplyScope::Mine);

        return Inertia::render('saved-replies/Index', [
            'replies' => SavedReplyResource::collection($this->query->paginate($user, $filters)),
            'filters' => ['q' => $filters['q'], 'scope' => $filters['scope']?->value],
            'categories' => $this->query->categoryOptions(),
            'canManageShared' => $user->can('create', [SavedReply::class, true]),
        ]);
    }

    public function picker(PickerRequest $request): AnonymousResourceCollection
    {
        return SavedReplyResource::collection(
            $this->query->picker($this->user($request), $request->term(), $request->categoryId()),
        );
    }

    public function store(StoreRequest $request, CreateSavedReply $create): RedirectResponse
    {
        $create->handle($this->user($request), $request->replyData());

        return back()->with('success', 'Saved reply created.');
    }

    public function update(UpdateRequest $request, SavedReply $savedReply, UpdateSavedReply $update): RedirectResponse
    {
        $update->handle($savedReply, $request->replyData());

        return back()->with('success', 'Saved reply updated.');
    }

    public function destroy(SavedReply $savedReply, DeleteSavedReply $delete): RedirectResponse
    {
        $delete->handle($savedReply);

        return back()->with('success', 'Saved reply deleted.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
