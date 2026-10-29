<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Services\Admin\AdminCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryController extends Controller
{
    public function index(AdminCatalog $catalog): AnonymousResourceCollection
    {
        return CategoryResource::collection($catalog->activeCategories());
    }
}
