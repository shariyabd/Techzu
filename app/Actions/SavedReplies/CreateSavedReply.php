<?php

declare(strict_types=1);

namespace App\Actions\SavedReplies;

use App\Models\SavedReply;
use App\Models\User;

final class CreateSavedReply
{
    /** @param array{title: string, body: string, category_id: int|null, shared: bool} $data */
    public function handle(User $author, array $data): SavedReply
    {
        $savedReply = new SavedReply;
        $savedReply->fill([
            'title' => $data['title'],
            'body' => $data['body'],
            'category_id' => $data['category_id'],
        ]);
        $savedReply->user_id = $data['shared'] ? null : $author->id;
        $savedReply->save();

        return $savedReply;
    }
}
