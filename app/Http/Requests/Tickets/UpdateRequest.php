<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

final class UpdateRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $view = $gate->inspect('view', $this->ticket());

        return $view->denied() ? $view : $gate->inspect('update', $this->ticket());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $canChangeCategory = Gate::forUser($this->user())->allows('changePriority', $this->ticket());

        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'category_id' => $canChangeCategory
                ? ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)]
                : ['prohibited'],
        ];
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('UpdateRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }
}
