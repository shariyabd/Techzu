<?php

declare(strict_types=1);

namespace App\Http\Requests\Holidays;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }

    public function year(): int
    {
        return $this->filled('year')
            ? $this->integer('year')
            : CarbonImmutable::now((string) config('business_hours.timezone'))->year;
    }
}
