<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateHolidayRequest extends StoreHolidayRequest
{
    protected function uniqueDate(): Unique
    {
        return Rule::unique('holidays', 'date')->ignore($this->route('holiday'));
    }
}
