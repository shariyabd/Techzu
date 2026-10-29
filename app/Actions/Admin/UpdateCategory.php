<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;

final class UpdateCategory
{
    /** @param array{name: string, slug: string, is_active?: bool} $data */
    public function handle(Category $category, array $data): Category
    {
        $category->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);
        $category->save();

        return $category;
    }
}
