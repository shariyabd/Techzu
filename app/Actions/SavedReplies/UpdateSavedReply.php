<?php

declare(strict_types=1);

namespace App\Actions\SavedReplies;

use App\Models\SavedReply;

final class UpdateSavedReply
{
    /** @param array{title: string, body: string, category_id: int|null} $data */
    public function handle(SavedReply $savedReply, array $data): SavedReply
    {
        $savedReply->fill([
            'title' => $data['title'],
            'body' => $data['body'],
            'category_id' => $data['category_id'],
        ]);
        $savedReply->save();

        return $savedReply;
    }
}
