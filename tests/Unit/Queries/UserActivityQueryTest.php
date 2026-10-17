<?php

declare(strict_types=1);

use App\Enums\ActivityScale;
use App\Models\Commit;
use App\Models\User;
use App\Queries\UserActivityQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 30));
});

it('picks the smallest scale that fits all of the visible activity', function (string $since, ActivityScale $scale): void {
    $user = User::factory()->create(['created_at' => now()->subYears(2)]);

    Commit::factory()->for($user)->create(['created_at' => $since]);
    Commit::factory()->for($user)->create();

    expect(resolve(UserActivityQuery::class)->get($user, $user)['scale'])->toBe($scale);
})->with([
    'today' => ['2026-10-15 00:00:00', ActivityScale::Day],
    'start of the last 24 hours' => ['2026-10-14 13:00:00', ActivityScale::Day],
    'just before the last 24 hours' => ['2026-10-14 12:59:59', ActivityScale::Week],
    'start of the last 7 days' => ['2026-10-09 00:00:00', ActivityScale::Week],
    'just before the last 7 days' => ['2026-10-08 23:59:59', ActivityScale::Month],
    'start of the last 30 days' => ['2026-09-16 00:00:00', ActivityScale::Month],
    'just before the last 30 days' => ['2026-09-15 23:59:59', ActivityScale::Year],
    'years ago' => ['2024-01-01 00:00:00', ActivityScale::Year],
]);

it('picks the scale from the account creation when there are no visible commits', function (string $createdAt, ActivityScale $scale): void {
    $user = User::factory()->create(['created_at' => $createdAt]);

    Commit::factory()->for($user)->pending()->create(['created_at' => '2024-01-01 00:00:00']);

    expect(resolve(UserActivityQuery::class)->get($user, User::factory()->create()))
        ->scale->toBe($scale)
        ->buckets->each(fn ($bucket) => $bucket->count->toBe(0));
})->with([
    'just now' => ['2026-10-15 12:30:00', ActivityScale::Day],
    'three days ago' => ['2026-10-12 09:00:00', ActivityScale::Week],
    'two weeks ago' => ['2026-10-01 09:00:00', ActivityScale::Month],
    'two years ago' => ['2024-10-15 09:00:00', ActivityScale::Year],
]);

it('counts the commits of each hour of the last 24 hours as utc instants', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => '2026-10-15 12:00:00']);
    Commit::factory()->for($user)->create(['created_at' => '2026-10-15 09:59:59']);
    Commit::factory()->for($user)->create(['created_at' => '2026-10-14 13:00:00']);
    Commit::factory()->create();

    $activity = resolve(UserActivityQuery::class)->get($user, $user);

    expect($activity['scale'])->toBe(ActivityScale::Day)
        ->and($activity['buckets'])->toHaveCount(24)
        ->and($activity['buckets'][0])->toBe(['date' => '2026-10-14T13:00:00Z', 'count' => 1])
        ->and($activity['buckets'][1])->toBe(['date' => '2026-10-14T14:00:00Z', 'count' => 0])
        ->and($activity['buckets'][20])->toBe(['date' => '2026-10-15T09:00:00Z', 'count' => 1])
        ->and($activity['buckets'][21])->toBe(['date' => '2026-10-15T10:00:00Z', 'count' => 0])
        ->and($activity['buckets'][23])->toBe(['date' => '2026-10-15T12:00:00Z', 'count' => 3]);
});

it('aggregates hourly activity without hydrating individual commits', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(30)->create(['body' => 'An hourly commit']);

    Event::fake(['eloquent.retrieved: '.Commit::class]);

    $activity = resolve(UserActivityQuery::class)->get($user, null, ActivityScale::Day);

    expect($activity['buckets'])->toHaveCount(24)
        ->and($activity['buckets'][23]['count'])->toBe(30);

    Event::assertNotDispatched('eloquent.retrieved: '.Commit::class);
});

it('keeps hourly buckets in utc regardless of the database timezone', function (string $timezone): void {
    DB::select("select set_config('TimeZone', ?, true)", [$timezone]);
    $user = User::factory()->create();

    Commit::factory()->for($user)->create(['created_at' => '2026-10-14 23:59:59']);
    Commit::factory()->for($user)->create(['created_at' => '2026-10-15 00:00:00']);
    Commit::factory()->for($user)->create(['created_at' => '2026-10-15 00:59:59']);

    $activity = resolve(UserActivityQuery::class)->get($user, null, ActivityScale::Day);

    expect($activity['buckets'][10])->toBe(['date' => '2026-10-14T23:00:00Z', 'count' => 1])
        ->and($activity['buckets'][11])->toBe(['date' => '2026-10-15T00:00:00Z', 'count' => 2])
        ->and($activity['buckets'][12])->toBe(['date' => '2026-10-15T01:00:00Z', 'count' => 0]);
})->with(['UTC', 'Asia/Kolkata', 'America/New_York']);

it('counts the commits of each day of the last 7 days', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => '2026-10-12 23:59:59']);
    Commit::factory()->for($user)->create(['created_at' => '2026-10-09 00:00:00']);

    $activity = resolve(UserActivityQuery::class)->get($user, $user);

    expect($activity['scale'])->toBe(ActivityScale::Week)
        ->and($activity['buckets'])->toBe([
            ['date' => '2026-10-09', 'count' => 1],
            ['date' => '2026-10-10', 'count' => 0],
            ['date' => '2026-10-11', 'count' => 0],
            ['date' => '2026-10-12', 'count' => 1],
            ['date' => '2026-10-13', 'count' => 0],
            ['date' => '2026-10-14', 'count' => 0],
            ['date' => '2026-10-15', 'count' => 2],
        ]);
});

it('counts the commits of each day of the last 30 days', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->count(2)->create(['created_at' => '2026-09-16 08:00:00']);

    $activity = resolve(UserActivityQuery::class)->get($user, $user);

    expect($activity['scale'])->toBe(ActivityScale::Month)
        ->and($activity['buckets'])->toHaveCount(30)
        ->and($activity['buckets'][0])->toBe(['date' => '2026-09-16', 'count' => 2])
        ->and($activity['buckets'][1])->toBe(['date' => '2026-09-17', 'count' => 0])
        ->and($activity['buckets'][29])->toBe(['date' => '2026-10-15', 'count' => 1]);
});

it('counts the commits of each day since the start of the week a year ago', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(2)->create();
    Commit::factory()->for($user)->create(['created_at' => now()->subDays(3)]);
    Commit::factory()->for($user)->create(['created_at' => now()->setDate(2025, 10, 12)]);
    Commit::factory()->for($user)->create(['created_at' => now()->setDate(2025, 10, 11)]);
    Commit::factory()->create();

    $activity = resolve(UserActivityQuery::class)->get($user, $user);

    expect($activity['scale'])->toBe(ActivityScale::Year)
        ->and($activity['buckets'])->toHaveCount(369)
        ->and($activity['buckets'][0])->toBe(['date' => '2025-10-12', 'count' => 1])
        ->and($activity['buckets'][1])->toBe(['date' => '2025-10-13', 'count' => 0])
        ->and($activity['buckets'][365])->toBe(['date' => '2026-10-12', 'count' => 1])
        ->and($activity['buckets'][368])->toBe(['date' => '2026-10-15', 'count' => 2]);
});

it('only counts the commits visible to the viewer', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    $query = resolve(UserActivityQuery::class);

    expect($query->get($user, User::factory()->create())['buckets'][23]['count'])->toBe(1)
        ->and($query->get($user, $user)['buckets'][23]['count'])->toBe(3);
});

it('only picks the scale from the commits visible to the viewer', function (): void {
    $user = User::factory()->create(['created_at' => now()->subYears(2)]);

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create(['created_at' => now()->subDays(3)]);

    $query = resolve(UserActivityQuery::class);

    expect($query->get($user, User::factory()->create())['scale'])->toBe(ActivityScale::Day)
        ->and($query->get($user, $user)['scale'])->toBe(ActivityScale::Week);
});

it('does not count deleted commits', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create()->delete();

    expect(resolve(UserActivityQuery::class)->get($user, $user)['buckets'][23]['count'])->toBe(1);
});

it('only counts approved commits for guests', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->pending()->create();
    Commit::factory()->for($user)->rejected()->create();

    expect(resolve(UserActivityQuery::class)->get($user, null)['buckets'][23]['count'])->toBe(1);
});

it('counts the commits of the given scale whatever the visible activity', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->count(2)->create();

    $activity = resolve(UserActivityQuery::class)->get($user, null, ActivityScale::Year);

    expect($activity['scale'])->toBe(ActivityScale::Year)
        ->and($activity['buckets'])->toHaveCount(369)
        ->and($activity['buckets'][368])->toBe(['date' => '2026-10-15', 'count' => 2]);
});
