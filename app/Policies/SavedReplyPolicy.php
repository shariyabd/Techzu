<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class SavedReplyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SavedRepliesUse->value);
    }

    public function view(User $user, SavedReply $savedReply): Response
    {
        if (! $user->can(Permission::SavedRepliesUse->value)) {
            return Response::deny();
        }

        return $savedReply->isShared() || $savedReply->isOwnedBy($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, bool $shared = false): bool
    {
        if (! $user->can(Permission::SavedRepliesUse->value)) {
            return false;
        }

        return ! $shared || $user->can(Permission::SavedRepliesManageShared->value);
    }

    public function update(User $user, SavedReply $savedReply): Response
    {
        return $this->manage($user, $savedReply);
    }

    public function delete(User $user, SavedReply $savedReply): Response
    {
        return $this->manage($user, $savedReply);
    }

    private function manage(User $user, SavedReply $savedReply): Response
    {
        $view = $this->view($user, $savedReply);

        if ($view->denied()) {
            return $view;
        }

        if ($savedReply->isOwnedBy($user)) {
            return Response::allow();
        }

        return $user->can(Permission::SavedRepliesManageShared->value)
            ? Response::allow()
            : Response::deny();
    }
}
