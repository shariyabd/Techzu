<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
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
        ];
    }

    public function scope(): string
    {
        return $this->validated('scope') === 'resolved' ? 'resolved' : 'active';
    }
}
