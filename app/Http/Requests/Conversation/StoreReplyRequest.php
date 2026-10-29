<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;
use LogicException;

final class StoreReplyRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->actor())->inspect('reply', $this->ticket());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions', []);

        return [
            'body' => ['required', 'string', 'max:20000'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files', 5)],
            'attachments.*' => [
                'required',
                File::types($extensions)->extensions($extensions)->max((int) config('attachments.max_kb', 10240)),
            ],
        ];
    }

    public function replyBody(): string
    {
        return $this->string('body')->toString();
    }

    public function wantsInternal(): bool
    {
        return $this->boolean('is_internal');
    }

    /** @return list<UploadedFile> */
    public function attachmentFiles(): array
    {
        return array_values(Arr::wrap($this->file('attachments', [])));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('StoreReplyRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }

    public function actor(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('StoreReplyRequest requires an authenticated user.');
        }

        return $user;
    }
}
