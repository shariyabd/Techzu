<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\SavedReplies\CreateSavedReply;
use App\Actions\SavedReplies\DeleteSavedReply;
use App\Actions\SavedReplies\UpdateSavedReply;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavedReplies\ListRequest;
use App\Http\Requests\SavedReplies\StoreRequest;
use App\Http\Requests\SavedReplies\UpdateRequest;
use App\Http\Resources\V1\SavedReplyResource;
use App\Models\SavedReply;
use App\Models\User;
use App\Services\SavedReplies\SavedReplyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class SavedReplyController extends Controller
{
    public function __construct(private readonly SavedReplyQuery $query) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        return SavedReplyResource::collection($this->query->paginate($this->user($request), $request->filters()));
    }

    public function store(StoreRequest $request, CreateSavedReply $create): JsonResponse
    {
        $savedReply = $create->handle($this->user($request), $request->replyData());

        return SavedReplyResource::make($this->query->load($savedReply))->response()->setStatusCode(201);
    }

    public function update(UpdateRequest $request, SavedReply $savedReply, UpdateSavedReply $update): SavedReplyResource
    {
        return SavedReplyResource::make($this->query->load($update->handle($savedReply, $request->replyData())));
    }

    public function destroy(SavedReply $savedReply, DeleteSavedReply $delete): Response
    {
        $delete->handle($savedReply);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
