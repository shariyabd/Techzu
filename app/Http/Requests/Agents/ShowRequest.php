<?php

declare(strict_types=1);

namespace App\Http\Requests\Agents;

use App\Services\Agents\AgentDirectory;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ShowRequest extends ListRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('viewAgentProfile', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tab' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(AgentDirectory::TABS))],
        ];
    }

    public function tab(): string
    {
        return $this->filled('tab') ? $this->string('tab')->toString() : AgentDirectory::DEFAULT_TAB;
    }
}
