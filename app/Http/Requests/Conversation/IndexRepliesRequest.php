<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class IndexRepliesRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->actor())->inspect('view', $this->ticket());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('tickets.per_page.default', 20));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('IndexRepliesRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }

    public function actor(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('IndexRepliesRequest requires an authenticated user.');
        }

        return $user;
    }
}
