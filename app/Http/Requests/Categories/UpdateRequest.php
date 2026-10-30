<?php

declare(strict_types=1);

namespace App\Http\Requests\Categories;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateRequest extends StoreRequest
{
    protected function uniqueSlug(): Unique
    {
        return Rule::unique('categories', 'slug')->ignore($this->route('category'));
    }
}
