<?php

declare(strict_types=1);

namespace App\Http\Requests\Holidays;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateRequest extends StoreRequest
{
    protected function uniqueDate(): Unique
    {
        return Rule::unique('holidays', 'date')->ignore($this->route('holiday'));
    }
}
