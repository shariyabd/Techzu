<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

final class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'unread' => ['sometimes', 'boolean'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', (int) config('tickets.per_page.default', 20));
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }
}
