<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Model;

class SlaTarget extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'priority',
        'resolution_minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'resolution_minutes' => 'integer',
        ];
    }
}
