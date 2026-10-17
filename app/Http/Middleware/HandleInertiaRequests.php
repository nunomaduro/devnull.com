<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Log;
use App\Models\Commit;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;
use App\Queries\SuggestedUsersQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $user = $request->user();
        $showsWhoToFollow = $user instanceof User && $request->cookie('who_to_follow') !== 'false';

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn (): ?User => $request->user()?->loadCounts(),
                'isAdmin' => $isAdmin,
                'githubIssueCommandsEnabled' => fn (): bool => $user instanceof User && $user->hasGitHubIssueAuthorization(),
                'log' => fn (): ?Log => $request->user()?->log,
                'unreadNotificationsCount' => fn (): int => $user instanceof User ? resolve(NotificationGroupsQuery::class)->unreadCount($user) : 0,
                'pasteis' => fn (): ?array => $user?->pasteis_mode ? [
                    'remaining' => $user->pasteisRemaining(),
                    'day' => today()->toDateString(),
                ] : null,
            ],
            'hasUnmoderatedCommits' => fn (): bool => $isAdmin && Commit::query()->pending()->exists(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'showsWhoToFollow' => $showsWhoToFollow,
            // Onboarding has no sidebar to show them in, and the people followed during it change who is suggested.
            ...$user instanceof User && $showsWhoToFollow && ! $request->routeIs('onboarding.show') ? [
                'suggestedUsers' => Inertia::defer(fn (): Collection => resolve(SuggestedUsersQuery::class)->get(
                    $user,
                    round: max(0, (int) $request->cookie('who_to_follow_round')),
                ))->once(),
            ] : [],
        ];
    }
}
