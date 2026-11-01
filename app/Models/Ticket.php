<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EscalationLevel;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Services\Tickets\TicketVisibility;
use Carbon\CarbonImmutable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Activity;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subject',
        'description',
        'priority',
        'category_id',
        'customer_id',
        'created_by_id',
        'external_reference',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'escalation_level' => 0,
        'escalation_notified_level' => 0,
        'sla_paused_seconds' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'escalation_level' => EscalationLevel::class,
            'escalation_notified_level' => EscalationLevel::class,
            'ticket_import_id' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'sla_started_at' => 'immutable_datetime',
            'sla_due_at' => 'immutable_datetime',
            'sla_warning_at' => 'immutable_datetime',
            'sla_paused_at' => 'immutable_datetime',
            'sla_paused_seconds' => 'integer',
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return HasMany<TicketReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /** @return MorphMany<Activity, $this> */
    public function activitiesVisibleTo(User $user): MorphMany
    {
        $activities = $this->activities()->where('log_name', 'ticket');
        $hidden = TicketVisibility::of($user)->hiddenActivityEvents();

        if ($hidden !== []) {
            $activities->whereNotIn('event', $hidden);
        }

        return $activities;
    }

    /** @param Builder<Ticket> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        TicketVisibility::of($user)->restrictTickets($query);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isImported(): bool
    {
        return $this->getAttribute('ticket_import_id') !== null;
    }

    public function isPaused(): bool
    {
        return $this->sla_paused_at !== null;
    }

    public function slaState(?CarbonImmutable $now = null): SlaState
    {
        $now ??= CarbonImmutable::now();

        if (! $this->isActive()) {
            return SlaState::Stopped;
        }

        if ($this->isPaused()) {
            return SlaState::Paused;
        }

        if ($now->greaterThanOrEqualTo($this->sla_due_at)) {
            return SlaState::Breached;
        }

        if ($now->greaterThanOrEqualTo($this->sla_warning_at)) {
            return SlaState::Approaching;
        }

        return SlaState::Within;
    }
}
