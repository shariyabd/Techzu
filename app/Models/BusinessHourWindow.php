<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessHourWindow extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'weekday',
        'starts_at',
        'ends_at',
    ];
}
