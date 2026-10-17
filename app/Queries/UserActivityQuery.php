<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ActivityScale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

final readonly class UserActivityQuery
{
    /**
     * Hourly buckets are keyed by their UTC start instant so the client can label them in local time, while daily buckets are UTC dates.
     * Without a scale, it is the smallest one that fits all of the visible activity.
     *
     * @return array{scale: ActivityScale, buckets: list<array{date: string, count: int}>}
     */
    public function get(User $user, ?User $viewer, ?ActivityScale $scale = null): array
    {
        $now = CarbonImmutable::now();

        $scale ??= $this->scale($user, $viewer, $now);

        $from = $scale->start($now);

        $commits = $user->commits()
            ->visibleTo($viewer)
            ->where('created_at', '>=', $from);

        $bucket = $scale->isHourly() ? "date_trunc('hour', created_at)" : 'date(created_at)';

        $counts = $commits->selectRaw("{$bucket} as bucket, count(*) as aggregate")
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket')
            ->mapWithKeys(fn (mixed $count, int|string $date): array => [
                $scale->isHourly() ? CarbonImmutable::parse((string) $date, 'UTC')->toIso8601ZuluString() : $date => is_numeric($count) ? (int) $count : 0,
            ])
            ->all();

        $period = $scale->isHourly()
            ? CarbonPeriod::create($from, '1 hour', $now->startOfHour())
            : CarbonPeriod::create($from, '1 day', $now->startOfDay());

        return [
            'scale' => $scale,
            'buckets' => array_values(array_map(
                function (CarbonInterface $start) use ($scale, $counts): array {
                    $date = $scale->isHourly() ? $start->toIso8601ZuluString() : $start->toDateString();

                    return ['date' => $date, 'count' => $counts[$date] ?? 0];
                },
                $period->toArray(),
            )),
        ];
    }

    private function scale(User $user, ?User $viewer, CarbonImmutable $now): ActivityScale
    {
        $firstCommitAt = $user->commits()->visibleTo($viewer)->min('created_at');

        return ActivityScale::since(
            is_string($firstCommitAt) ? CarbonImmutable::parse($firstCommitAt) : $user->created_at,
            $now,
        );
    }
}
