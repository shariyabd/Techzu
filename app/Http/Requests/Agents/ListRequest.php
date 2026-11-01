<?php

declare(strict_types=1);

namespace App\Http\Requests\Agents;

use App\Models\User;
use App\Services\Agents\AgentDirectory;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ListRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('viewAgentDirectory', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', Rule::in(array_keys(AgentDirectory::ROLE_FILTERS))],
            'availability' => ['sometimes', 'nullable', Rule::in(array_keys(AgentDirectory::AVAILABILITY_FILTERS))],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(AgentDirectory::SORTS))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'list_pages' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: string|null, role: string, availability: string|null, category_id: int|null, sort: string, per_page: int} */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            'role' => $this->filled('role') ? $this->string('role')->toString() : AgentDirectory::DEFAULT_ROLE,
            'availability' => $this->filled('availability') ? $this->string('availability')->toString() : null,
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'sort' => $this->filled('sort') ? $this->string('sort')->toString() : AgentDirectory::DEFAULT_SORT,
            'per_page' => $this->integer('per_page', 20),
        ];
    }

    public function listPages(): int
    {
        return $this->integer('list_pages', 1);
    }
}
