<?php

declare(strict_types=1);

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
        ];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        /** @var list<mixed> $names */
        $names = $this->validated('permissions', []);

        return array_map(static fn (mixed $name): string => (string) $name, $names);
    }
}
