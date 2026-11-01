<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedReplies;

final class UpdateRequest extends StoreRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['shared']);

        return $rules;
    }
}
