<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncAgentProfile
{
    /** @param list<int> $categoryIds */
    public function handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User
    {
        return DB::transaction(function () use ($agent, $categoryIds, $isAvailable): User {
            $agent->categories()->sync(array_values(array_unique($categoryIds)));
            $agent->is_available = $isAvailable;
            $agent->save();

            return $agent;
        });
    }
}
