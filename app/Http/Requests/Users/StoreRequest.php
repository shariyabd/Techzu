<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} */
    public function userData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->filled('password') ? $this->string('password')->toString() : null,
            'role' => $this->string('role')->toString(),
            'category_ids' => $this->has('category_ids')
                ? array_values(array_map('intval', (array) $this->validated('category_ids', [])))
                : null,
            'is_available' => $this->has('is_available') ? $this->boolean('is_available') : null,
        ];
    }
}
