<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketReply extends Model
{
    /** @use HasFactory<TicketReplyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'body',
        'is_internal',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_internal' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'reply_id');
    }

    /** @param Builder<TicketReply> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isStaff()) {
            $query->where($query->qualifyColumn('is_internal'), false);
        }
    }
}
