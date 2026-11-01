<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\PasswordLength;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:'.app(PasswordLength::class)->max()],
            'remember' => ['boolean'],
        ];
    }
}
