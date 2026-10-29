<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_available' => true,
            'last_assigned_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user): User => $user->assignRole(Role::Admin));
    }

    /** @param list<int> $categoryIds */
    public function agent(array $categoryIds = []): static
    {
        return $this->afterCreating(function (User $user) use ($categoryIds): void {
            $user->assignRole(Role::Agent);
            $user->categories()->sync($categoryIds);
        });
    }

    public function customer(): static
    {
        return $this->afterCreating(fn (User $user): User => $user->assignRole(Role::Customer));
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_available' => false,
        ]);
    }
}
