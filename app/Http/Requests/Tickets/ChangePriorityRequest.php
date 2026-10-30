<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

final class ChangePriorityRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $view = $gate->inspect('view', $this->ticket());

        return $view->denied() ? $view : $gate->inspect('changePriority', $this->ticket());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'priority' => ['required', Rule::enum(TicketPriority::class)],
        ];
    }

    public function targetPriority(): TicketPriority
    {
        return TicketPriority::from((string) $this->validated('priority'));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('ChangePriorityRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }
}
