<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Services\Users\AvatarRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => ['required', ...app(AvatarRules::class)->file()],
        ];
    }

    public function avatar(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->validated('avatar');

        return $file;
    }
}
