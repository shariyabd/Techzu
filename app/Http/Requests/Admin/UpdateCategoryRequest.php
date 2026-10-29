<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateCategoryRequest extends StoreCategoryRequest
{
    protected function uniqueSlug(): Unique
    {
        return Rule::unique('categories', 'slug')->ignore($this->route('category'));
    }
}
