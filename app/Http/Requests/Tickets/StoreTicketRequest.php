<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class StoreTicketRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('create', Ticket::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions', []);

        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'customer_email' => $this->canCreateOnBehalf()
                ? ['required', 'string', 'email', $this->mustBeCustomer()]
                : ['prohibited'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files', 5)],
            'attachments.*' => [File::types($extensions)->extensions($extensions)->max((int) config('attachments.max_kb', 10240))],
        ];
    }

    /** @return array{subject: string, description: string, priority: string, category_id: int, customer_id?: int} */
    public function actionData(): array
    {
        $data = [
            'subject' => (string) $this->validated('subject'),
            'description' => (string) $this->validated('description'),
            'priority' => (string) $this->validated('priority'),
            'category_id' => (int) $this->validated('category_id'),
        ];

        $email = $this->validated('customer_email');
        if (is_string($email)) {
            $data['customer_id'] = (int) User::query()->where('email', $email)->value('id');
        }

        return $data;
    }

    /** @return list<UploadedFile> */
    public function attachmentFiles(): array
    {
        return array_values(Arr::wrap($this->file('attachments')));
    }

    private function canCreateOnBehalf(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('createOnBehalf', Ticket::class);
    }

    private function mustBeCustomer(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $isCustomer = User::query()
                ->where('email', $value)
                ->role(Role::Customer->value)
                ->exists();

            if (! $isCustomer) {
                $fail('The selected customer does not exist.');
            }
        };
    }
}
