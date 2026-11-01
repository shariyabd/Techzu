<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_available
 * @property CarbonImmutable|null $last_assigned_at
 * @property string|null $avatar_path
 * @property CarbonImmutable|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasApiTokens<PersonalAccessToken|TransientToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_available',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_available' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_available' => 'boolean',
            'last_assigned_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'agent_category');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function customerTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'customer_id');
    }

    public function avatarUrl(): ?string
    {
        $path = $this->getAttributeFromArray('avatar_path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        return route('avatars.show', ['user' => $this->getKey(), 'v' => substr(sha1($path), 0, 12)]);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    /** @param Builder<User> $query */
    public function scopeAvailableAgents(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Role::Agent->value))
            ->where($query->qualifyColumn('is_available'), true);
    }

    /** @param Builder<User> $query */
    public function scopeAdmins(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', Role::Admin->value));
    }
}
