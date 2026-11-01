<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

final class LookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function term(): ?string
    {
        $term = $this->filled('q') ? trim($this->string('q')->toString()) : '';

        return $term === '' ? null : $term;
    }

    public function customerId(): ?int
    {
        return $this->filled('id') ? $this->integer('id') : null;
    }
}
