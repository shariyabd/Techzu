<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class AdminCatalog
{
    /** @return LengthAwarePaginator<int, Category> */
    public function categories(int $perPage = 20): LengthAwarePaginator
    {
        return Category::query()->withCount('tickets')->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    /** @return Collection<int, Category> */
    public function activeCategories(): Collection
    {
        return Category::query()->active()->orderBy('name')->limit(500)->get(['id', 'name', 'slug', 'is_active']);
    }

    /** @return LengthAwarePaginator<int, Holiday> */
    public function holidays(int $year, int $perPage = 50): LengthAwarePaginator
    {
        return Holiday::query()
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->orderBy('date')
            ->paginate($perPage)
            ->withQueryString();
    }
}
