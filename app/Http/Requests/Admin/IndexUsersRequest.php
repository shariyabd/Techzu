<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUsersRequest extends FormRequest
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
            'role' => ['nullable', Rule::enum(Role::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{q: string|null, role: string|null, per_page: int} */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
            'role' => $this->filled('role') ? $this->string('role')->toString() : null,
            'per_page' => $this->integer('per_page', 20),
        ];
    }
}
