<?php

declare(strict_types=1);

namespace App\Services\SavedReplies;

use App\Enums\SavedReplyScope;
use App\Models\Category;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class SavedReplyQuery
{
    public const int PICKER_LIMIT = 50;

    private const array COLUMNS = [
        'saved_replies.id', 'saved_replies.user_id', 'saved_replies.category_id', 'saved_replies.title',
        'saved_replies.body', 'saved_replies.created_at', 'saved_replies.updated_at',
    ];

    private const array RELATIONS = ['category:id,name'];

    /**
     * @param  array{q: string|null, scope: SavedReplyScope|null, per_page: int}  $filters
     * @return LengthAwarePaginator<int, SavedReply>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $scope = $filters['scope'];

        return $this->search(SavedReply::query(), $filters['q'])
            ->select(self::COLUMNS)
            ->with(self::RELATIONS)
            ->when($scope === null, static function (Builder $query) use ($user): void {
                $query->visibleTo($user);
            })
            ->when($scope === SavedReplyScope::Mine, static function (Builder $query) use ($user): void {
                $query->where('saved_replies.user_id', $user->id);
            })
            ->when($scope === SavedReplyScope::Team, static function (Builder $query): void {
                $query->whereNull('saved_replies.user_id');
            })
            ->orderBy('saved_replies.title')
            ->orderBy('saved_replies.id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /** @return Collection<int, SavedReply> */
    public function picker(User $user, ?string $term, ?int $categoryId): Collection
    {
        return $this->search(SavedReply::query(), $term)
            ->select(self::COLUMNS)
            ->with(self::RELATIONS)
            ->visibleTo($user)
            ->when($categoryId !== null, static function (Builder $query) use ($categoryId): void {
                $query->orderByRaw('case when saved_replies.category_id = ? then 0 else 1 end', [$categoryId]);
            })
            ->orderBy('saved_replies.title')
            ->orderBy('saved_replies.id')
            ->limit(self::PICKER_LIMIT)
            ->get();
    }

    public function load(SavedReply $savedReply): SavedReply
    {
        return $savedReply->load(self::RELATIONS);
    }

    /** @return list<array{id: int, name: string}> */
    public function categoryOptions(): array
    {
        return Category::query()
            ->active()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<SavedReply>  $query
     * @return Builder<SavedReply>
     */
    private function search(Builder $query, ?string $term): Builder
    {
        if ($term === null) {
            return $query;
        }

        $pattern = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(static function (Builder $match) use ($pattern): void {
            $match->where('saved_replies.title', 'like', $pattern)->orWhere('saved_replies.body', 'like', $pattern);
        });
    }
}
