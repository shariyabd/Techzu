<?php

declare(strict_types=1);

namespace App\Actions\SavedReplies;

use App\Models\SavedReply;

final class DeleteSavedReply
{
    public function handle(SavedReply $savedReply): void
    {
        $savedReply->delete();
    }
}
