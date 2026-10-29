<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateCategory;
use App\Actions\Admin\DeleteCategory;
use App\Actions\Admin\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\V1\AdminCategoryResource;
use App\Models\Category;
use App\Services\Admin\AdminCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CategoryController extends Controller
{
    public function index(AdminCatalog $catalog): AnonymousResourceCollection
    {
        return AdminCategoryResource::collection($catalog->categories());
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): JsonResponse
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();

        return AdminCategoryResource::make($create->handle($data)->loadCount('tickets'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): AdminCategoryResource
    {
        /** @var array{name: string, slug: string, is_active?: bool} $data */
        $data = $request->validated();

        return AdminCategoryResource::make($update->handle($category, $data)->loadCount('tickets'));
    }

    public function destroy(Category $category, DeleteCategory $delete): Response|AdminCategoryResource
    {
        if ($delete->handle($category)) {
            return response()->noContent();
        }

        return AdminCategoryResource::make($category->loadCount('tickets'));
    }
}
