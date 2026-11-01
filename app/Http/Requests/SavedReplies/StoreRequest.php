<?php

declare(strict_types=1);

namespace App\Http\Requests\SavedReplies;

use App\Enums\SavedReplyPlaceholder;
use App\Models\SavedReply;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('create', [SavedReply::class, $this->boolean('shared')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000', $this->knownPlaceholders()],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'shared' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{title: string, body: string, category_id: int|null, shared: bool} */
    public function replyData(): array
    {
        return [
            'title' => $this->string('title')->trim()->toString(),
            'body' => $this->string('body')->toString(),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'shared' => $this->boolean('shared'),
        ];
    }

    protected function knownPlaceholders(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $unknown = SavedReplyPlaceholder::unknownIn($value);

            if ($unknown !== []) {
                $fail('Unknown placeholder: '.implode(', ', $unknown).'.');
            }
        };
    }
}
