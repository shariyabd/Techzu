<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Admin\CreateCategory;
use App\Actions\Admin\DeleteCategory;
use App\Actions\Admin\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\V1\AdminCategoryResource;
use App\Models\Category;
use App\Services\Admin\AdminCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function index(AdminCatalog $catalog): Response
    {
        return Inertia::render('admin/categories/Index', [
            'categories' => AdminCategoryResource::collection($catalog->categories()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categories/Create');
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): RedirectResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();
        $create->handle($data);

        return to_route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Request $request, Category $category): Response
    {
        return Inertia::render('admin/categories/Edit', [
            'category' => AdminCategoryResource::make($category->loadCount('tickets'))->resolve($request),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): RedirectResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();
        $update->handle($category, $data);

        return to_route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category, DeleteCategory $delete): RedirectResponse
    {
        return to_route('admin.categories.index')->with('success', $delete->handle($category)
            ? 'Category deleted.'
            : 'Category has tickets, so it was deactivated instead of deleted.');
    }
}
