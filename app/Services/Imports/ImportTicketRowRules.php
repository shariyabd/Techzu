<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

final class ImportTicketRowRules
{
    /** @param list<string> $activeCategorySlugs */
    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly string $timezone,
        private readonly array $activeCategorySlugs,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'external_reference' => ['nullable', 'string', 'max:64', Rule::unique('tickets', 'external_reference')],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:65535'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category' => ['required', 'string', Rule::in($this->activeCategorySlugs)],
            'customer_email' => ['bail', 'required', 'string', 'email', $this->customerExists(...)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'created_at' => ['nullable', 'string', $this->createdAtIsValid(...)],
        ];
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public static function parseCreatedAt(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function customerExists(string $attribute, mixed $value, Closure $fail): void
    {
        $isCustomer = User::query()
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->where('name', Role::Customer->value))
            ->where('email', (string) $value)
            ->exists();

        if (! $isCustomer) {
            $fail('The customer email must belong to an existing customer.');
        }
    }

    private function createdAtIsValid(string $attribute, mixed $value, Closure $fail): void
    {
        $createdAt = self::parseCreatedAt((string) $value, $this->timezone);

        if ($createdAt === null) {
            $fail('The created at field must be a valid date.');
        } elseif ($createdAt->greaterThan($this->now)) {
            $fail('The created at field must not be in the future.');
        }
    }
}
