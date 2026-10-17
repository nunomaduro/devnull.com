<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\CreateTomFriendship;
use App\Enums\Log;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * @property-read string $id
 * @property-read string $github_id
 * @property-read string|null $github_issue_token
 * @property-read CarbonInterface|null $github_issue_token_expires_at
 * @property-read string $username
 * @property-read string $name
 * @property-read string|null $email
 * @property-read string|null $avatar_url
 * @property-read string|null $bio
 * @property-read string|null $location
 * @property-read string|null $website
 * @property-read string|null $company
 * @property-read bool $has_sponsors_listing
 * @property-read int|null $preferred_lane
 * @property-read string|null $lane_color
 * @property-read bool $pasteis_mode
 * @property-read int $pasteis_received
 * @property-read int $pasteis_given_today
 * @property-read CarbonInterface|null $pasteis_given_on
 * @property-read string|null $pinned_commit_id
 * @property-read string|null $timezone
 * @property-read Log $log
 * @property-read CarbonInterface|null $onboarded_at
 * @property-read CarbonInterface|null $github_follows_imported_at
 * @property-read string|null $remember_token
 * @property-read CarbonInterface|null $github_synced_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Collection<int, Commit> $commits
 * @property-read Collection<int, Commit> $starredCommits
 * @property-read Collection<int, User> $following
 * @property-read Collection<int, User> $followers
 * @property-read Collection<int, User> $muting
 * @property-read Collection<int, User> $mutedBy
 * @property-read Collection<int, User> $blocking
 * @property-read Collection<int, User> $blockers
 * @property-read Collection<int, Notification> $notifications
 * @property-read Collection<int, SshKey> $sshKeys
 * @property-read Collection<int, PollVote> $pollVotes
 * @property-read int $commits_count
 * @property-read int $following_count
 * @property-read int $followers_count
 */
#[Hidden([
    'github_id',
    'github_issue_token',
    'github_issue_token_expires_at',
    'email',
    'remember_token',
    'preferred_lane',
    'lane_color',
    'pasteis_mode',
    'pasteis_received',
    'pasteis_given_today',
    'pasteis_given_on',
    'pinned_commit_id',
    'github_synced_at',
    'log',
    'onboarded_at',
    'github_follows_imported_at',
])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;

    public const int DAILY_PASTEIS = 10;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'log' => Log::Following->value,
        'github_issue_token' => null,
        'github_issue_token_expires_at' => null,
        'pasteis_mode' => false,
        'pasteis_received' => 0,
        'pasteis_given_today' => 0,
        'pasteis_given_on' => null,
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'github_id' => 'string',
            'github_issue_token' => 'encrypted',
            'github_issue_token_expires_at' => 'datetime',
            'username' => 'string',
            'name' => 'string',
            'email' => 'string',
            'avatar_url' => 'string',
            'bio' => 'string',
            'location' => 'string',
            'website' => 'string',
            'company' => 'string',
            'has_sponsors_listing' => 'boolean',
            'preferred_lane' => 'integer',
            'lane_color' => 'string',
            'pasteis_mode' => 'boolean',
            'pasteis_received' => 'integer',
            'pasteis_given_today' => 'integer',
            'pasteis_given_on' => 'date',
            'pinned_commit_id' => 'string',
            'timezone' => 'string',
            'log' => Log::class,
            'onboarded_at' => 'datetime',
            'github_follows_imported_at' => 'datetime',
            'remember_token' => 'string',
            'github_synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function hasGitHubIssueAuthorization(): bool
    {
        return filled($this->github_issue_token)
            && ($this->github_issue_token_expires_at === null || $this->github_issue_token_expires_at->isFuture());
    }

    /**
     * @return HasMany<Commit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    /**
     * @return HasMany<PollVote, $this>
     */
    public function pollVotes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /**
     * @return BelongsToMany<Commit, $this>
     */
    public function starredCommits(): BelongsToMany
    {
        return $this->belongsToMany(Commit::class, 'stars')->withTimestamps();
    }

    public function hasStarred(Commit $commit): bool
    {
        return $this->starredCommits()->whereKey($commit->id)->exists();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function muting(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'mutes', 'muter_id', 'muted_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function mutedBy(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'mutes', 'muted_id', 'muter_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blocks', 'blocker_id', 'blocked_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blocks', 'blocked_id', 'blocker_id')->withTimestamps();
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_id');
    }

    /**
     * Users sign in with GitHub, so they have no password.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function isAdmin(): bool
    {
        /** @var list<string> $admins */
        $admins = config('app.admins');

        return in_array(mb_strtolower($this->username), array_map(mb_strtolower(...), $admins), true);
    }

    /**
     * Unlike loadCount(), counts follows on the pivot table alone: the self-referencing
     * relations would otherwise join the users table for every follow. Commits are
     * counted as the viewer sees them, which is all of them for the user themselves.
     */
    public function loadCounts(?self $viewer = null): self
    {
        return $this->fillCounts($this->commits()->visibleTo($viewer ?? $this)->count());
    }

    /**
     * Counts what anyone may see about the user, so only approved commits.
     */
    public function loadPublicCounts(): self
    {
        return $this->fillCounts($this->commits()->approved()->count());
    }

    /**
     * Changes whenever anything drawn on the profile's link preview image may have changed.
     */
    public function openGraphImageVersion(): string
    {
        return hash('xxh3', (string) json_encode([
            ...$this->openGraphIdentity(),
            $this->bio,
            $this->commits_count,
            $this->following_count,
            $this->followers_count,
        ]));
    }

    /**
     * What every link preview image draws of the user, so saving anything else leaves them cached.
     *
     * @return list<int|string|null>
     */
    public function openGraphIdentity(): array
    {
        return [$this->name, $this->username, $this->avatar_url, $this->lane()];
    }

    /**
     * How link previews title the user, without repeating a name that is just the username.
     */
    public function nameWithHandle(): string
    {
        return $this->name === $this->username ? "@{$this->username}" : "{$this->name} (@{$this->username})";
    }

    public function isFollowing(self $user): bool
    {
        return $this->following()->newPivotQuery()->where('followed_id', $user->id)->exists();
    }

    public function isMuting(self $user): bool
    {
        return $this->muting()->newPivotQuery()->where('muted_id', $user->id)->exists();
    }

    public function isBlocking(self $user): bool
    {
        return $this->blocking()->newPivotQuery()->where('blocked_id', $user->id)->exists();
    }

    /**
     * A block works both ways, so either one blocking the other keeps them apart.
     */
    public function hasBlockWith(self $user): bool
    {
        return $this->blockerIdWith($user) !== null;
    }

    /**
     * Which of the two blocked the other, if either did.
     */
    public function blockerIdWith(self $user): ?string
    {
        $blockerId = DB::table('blocks')
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->where('blocker_id', $this->id)->where('blocked_id', $user->id))
            ->orWhere(fn (QueryBuilder $query): QueryBuilder => $query->where('blocker_id', $user->id)->where('blocked_id', $this->id))
            ->value('blocker_id');

        return is_string($blockerId) ? $blockerId : null;
    }

    /**
     * The ids of everyone the user blocks or is blocked by, as a subquery.
     */
    public function idsAcrossBlocks(): QueryBuilder
    {
        return $this->blocking()->newPivotQuery()->select('blocked_id')
            ->union($this->blockers()->newPivotQuery()->select('blocker_id'));
    }

    /**
     * The ids of everyone the user mutes, as a subquery.
     */
    public function mutedIds(): QueryBuilder
    {
        return $this->muting()->newPivotQuery()->select('muted_id');
    }

    public function pasteisRemaining(): int
    {
        return self::DAILY_PASTEIS - ($this->pasteis_given_on?->isToday() ? $this->pasteis_given_today : 0);
    }

    public function lane(): int|string
    {
        return $this->preferredLane() ?? $this->automaticLane();
    }

    public function preferredLane(): int|string|null
    {
        return $this->lane_color ?? $this->preferred_lane;
    }

    /**
     * The same hash of the id the frontend used before lanes could be chosen, so nobody's color changes on its own.
     */
    public function automaticLane(): int
    {
        $hash = 0;

        foreach (mb_str_split($this->id) as $character) {
            $hash = ($hash * 31 + mb_ord($character)) & 0xFFFFFFFF;
        }

        return $hash % 6;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'lane' => $this->lane(),
            'has_github_account' => $this->hasGitHubAccount(),
        ];
    }

    /**
     * Everyone signs in with GitHub except Tom, who was made up and has no GitHub profile to link to.
     */
    public function hasGitHubAccount(): bool
    {
        return $this->github_id !== CreateTomFriendship::GITHUB_ID;
    }

    private function fillCounts(int $commits): self
    {
        $counts = [
            'commits_count' => $commits,
            'followers_count' => $this->followers()->newPivotQuery()->count(),
            'following_count' => $this->following()->newPivotQuery()->count(),
        ];

        $this->forceFill($counts)->syncOriginalAttributes(array_keys($counts));

        return $this;
    }
}
