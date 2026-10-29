<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Category;

final class CreateCategory
{
    /** @param array{name: string, slug: string, is_active?: bool} $data */
    public function handle(array $data): Category
    {
        $category = new Category;
        $category->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $category->save();

        return $category;
    }
}
