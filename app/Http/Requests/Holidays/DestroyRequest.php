<?php

declare(strict_types=1);

namespace App\Http\Requests\Holidays;

use Illuminate\Foundation\Http\FormRequest;

final class DestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'recalculate_active_tickets' => ['sometimes', 'boolean'],
        ];
    }

    public function recalculateActiveTickets(): bool
    {
        return $this->boolean('recalculate_active_tickets');
    }
}
