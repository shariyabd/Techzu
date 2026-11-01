<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedReplies;

use Illuminate\Foundation\Http\FormRequest;

final class PickerRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function term(): ?string
    {
        $term = $this->filled('q') ? trim($this->string('q')->toString()) : '';

        return $term === '' ? null : $term;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? $this->integer('category_id') : null;
    }
}
