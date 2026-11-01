<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Services\Users\AvatarRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateRequest extends StoreRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', Password::defaults()],
            'avatar' => ['nullable', ...app(AvatarRules::class)->file()],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null, avatar: UploadedFile|null, remove_avatar: bool} */
    public function userData(): array
    {
        $avatar = $this->file('avatar');

        return [
            ...parent::userData(),
            'avatar' => $avatar instanceof UploadedFile ? $avatar : null,
            'remove_avatar' => $this->boolean('remove_avatar'),
        ];
    }
}
