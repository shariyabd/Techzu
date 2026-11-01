<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaSetting extends Model
{
    public const SINGLETON_ID = 1;

    /** @var list<string> */
    protected $fillable = [
        'timezone',
        'warning_threshold_percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warning_threshold_percent' => 'integer',
        ];
    }
}
