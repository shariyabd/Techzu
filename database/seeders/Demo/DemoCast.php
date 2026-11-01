<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Category;
use App\Models\User;
use InvalidArgumentException;

final class DemoCast
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<int, string> */
    private array $keysById = [];

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, string> */
    private array $companies = [];

    public function addUser(string $key, User $user, ?string $company): void
    {
        $this->users[$key] = $user;
        $this->keysById[$user->id] = $key;

        if ($company !== null) {
            $this->companies[$key] = $company;
        }
    }

    public function addCategory(Category $category): void
    {
        $this->categories[$category->slug] = $category;
    }

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new InvalidArgumentException("Unknown demo user [{$key}].");
    }

    public function userById(int $id): User
    {
        return $this->user($this->keysById[$id] ?? throw new InvalidArgumentException("Unknown demo user id [{$id}]."));
    }

    public function admin(): User
    {
        return $this->user('admin');
    }

    public function category(string $slug): Category
    {
        return $this->categories[$slug] ?? throw new InvalidArgumentException("Unknown demo category [{$slug}].");
    }

    public function company(string $key): string
    {
        return $this->companies[$key] ?? 'our company';
    }

    /** @return array<string, User> */
    public function users(): array
    {
        return $this->users;
    }

    public static function firstName(User $user): string
    {
        return explode(' ', $user->name)[0];
    }
}
