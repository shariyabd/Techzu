<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SavedReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $category_id
 * @property string $title
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SavedReply extends Model
{
    /** @use HasFactory<SavedReplyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'body',
        'category_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id !== null && $this->user_id === $user->id;
    }

    /** @param Builder<SavedReply> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(static function (Builder $visible) use ($query, $user): void {
            $visible->whereNull($query->qualifyColumn('user_id'))
                ->orWhere($query->qualifyColumn('user_id'), $user->id);
        });
    }
}
