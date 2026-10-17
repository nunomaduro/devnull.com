<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class MentionSuggestionsQuery
{
    /**
     * Without a term, the people the user follows, most recently followed first, so a bare "@" already has
     * suggestions. With one, the same matches as people search, people the user follows first within each tier.
     * Never the user themselves, people they mute, or anyone across a block.
     *
     * @return Collection<int, User>
     */
    public function get(User $user, string $term, int $limit = 6): Collection
    {
        $term = Str::of($term)
            ->trim()
            ->ltrim('@')
            ->lower()
            ->value();

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        if ($term === '') {
            return $user->following()
                ->whereNotIn('users.id', $user->mutedIds())
                ->whereNotIn('users.id', $user->idsAcrossBlocks())
                ->orderByPivot('created_at', 'desc')
                ->orderBy('username')
                ->limit($limit)
                ->get()
                ->each(fn (User $followed): User => $followed->unsetRelation('pivot'))
                ->toBase();
        }

        return User::query()
            ->whereKeyNot($user->id)
            ->whereNotIn('id', $user->mutedIds())
            ->whereNotIn('id', $user->idsAcrossBlocks())
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", ["%{$escaped}%"]))
            ->orderByRaw(
                "CASE WHEN LOWER(username) = ? THEN 0 WHEN LOWER(username) LIKE ? ESCAPE '!' THEN 1 WHEN LOWER(name) LIKE ? ESCAPE '!' THEN 2 ELSE 3 END",
                [$term, "{$escaped}%", "{$escaped}%"],
            )
            ->orderByDesc(fn (QueryBuilder $query): QueryBuilder => $query
                ->selectRaw('COUNT(*)')
                ->from('follows')
                ->where('follows.follower_id', $user->id)
                ->whereColumn('follows.followed_id', 'users.id'))
            ->orderBy('username')
            ->limit($limit)
            ->get()
            ->toBase();
    }
}
