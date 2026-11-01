<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedReplies;

use App\Enums\SavedReplyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListRequest extends FormRequest
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
            'scope' => ['nullable', Rule::enum(SavedReplyScope::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{q: string|null, scope: SavedReplyScope|null, per_page: int} */
    public function filters(?SavedReplyScope $defaultScope = null): array
    {
        $term = $this->filled('q') ? trim($this->string('q')->toString()) : '';

        return [
            'q' => $term === '' ? null : $term,
            'scope' => $this->enum('scope', SavedReplyScope::class) ?? $defaultScope,
            'per_page' => $this->integer('per_page', 20),
        ];
    }
}
