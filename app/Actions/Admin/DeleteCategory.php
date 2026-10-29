<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class DeleteCategory
{
    public function handle(Category $category): bool
    {
        return DB::transaction(function () use ($category): bool {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if ($locked->tickets()->exists()) {
                $locked->is_active = false;
                $locked->save();
                $category->is_active = false;
                $category->syncOriginal();

                return false;
            }

            $locked->delete();

            return true;
        });
    }
}
