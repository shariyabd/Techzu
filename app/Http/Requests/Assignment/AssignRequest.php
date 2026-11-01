<?php

declare(strict_types=1);

namespace App\Http\Requests\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

final class AssignRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $assign = $gate->inspect('assign', $this->ticket());

        if ($assign->denied() || ! $this->outsideCategory()) {
            return $assign;
        }

        return $gate->inspect('assignOutsideCategory', $this->ticket());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'outside_category' => ['sometimes', 'boolean'],
        ];
    }

    public function outsideCategory(): bool
    {
        return $this->boolean('outside_category');
    }

    public function targetAgent(): User
    {
        return User::query()->findOrFail((int) $this->validated('agent_id'));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('AssignRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }
}
