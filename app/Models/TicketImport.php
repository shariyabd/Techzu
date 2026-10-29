<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketImport extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'original_name',
        'path',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ImportStatus::Pending->value,
        'total_rows' => 0,
        'processed_rows' => 0,
        'succeeded_rows' => 0,
        'failed_rows' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'succeeded_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TicketImportError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(TicketImportError::class, 'ticket_import_id');
    }
}
