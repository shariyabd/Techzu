<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

final class ChangeStatusRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $view = $gate->inspect('view', $this->ticket());

        if ($view->denied()) {
            return $view;
        }

        $to = TicketStatus::tryFrom((string) $this->input('status'));

        return $gate->inspect('changeStatus', [$this->ticket(), $to]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ];
    }

    public function targetStatus(): TicketStatus
    {
        return TicketStatus::from((string) $this->validated('status'));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('ChangeStatusRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }
}
