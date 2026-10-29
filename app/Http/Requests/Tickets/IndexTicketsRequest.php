<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Tickets\TicketFilter;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class IndexTicketsRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('viewAny', Ticket::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::in(['active', 'resolved'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            'number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => ['string', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => $this->filled('created_from')
                ? ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:created_from']
                : ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sla_state' => ['sometimes', 'nullable', Rule::enum(SlaState::class)],
            'sort' => ['sometimes', Rule::in(array_keys(TicketFilter::SORTS))],
        ];
    }

    public function scope(): string
    {
        return $this->validated('scope') === 'resolved' ? 'resolved' : 'active';
    }
}
