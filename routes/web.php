<?php

declare(strict_types=1);

use App\Enums\Log;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\Cli\ScriptController;
use App\Http\Controllers\CommitApprovalController;
use App\Http\Controllers\CommitController;
use App\Http\Controllers\CommitImageController;
use App\Http\Controllers\CommitLinkPreviewController;
use App\Http\Controllers\CommitOpenGraphImageController;
use App\Http\Controllers\CommitPastelController;
use App\Http\Controllers\CommitPhotoController;
use App\Http\Controllers\CommitPinController;
use App\Http\Controllers\CommitPollVoteController;
use App\Http\Controllers\CommitPreviewController;
use App\Http\Controllers\CommitRejectionController;
use App\Http\Controllers\CommitReplyController;
use App\Http\Controllers\CommitReportController;
use App\Http\Controllers\CommitRestorationController;
use App\Http\Controllers\CommitSearchController;
use App\Http\Controllers\CommitSitemapController;
use App\Http\Controllers\CommitStarController;
use App\Http\Controllers\CommitStarCountController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\FailedJobRetryController;
use App\Http\Controllers\GitHubIssueAuthorizationController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueImageController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MentionSuggestionController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationGroupController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReportedCommitController;
use App\Http\Controllers\SafetyController;
use App\Http\Controllers\ScheduledCommitController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\UserActivityGraphController;
use App\Http\Controllers\UserBlockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserFeedController;
use App\Http\Controllers\UserFollowController;
use App\Http\Controllers\UserFollowersController;
use App\Http\Controllers\UserFollowingController;
use App\Http\Controllers\UserLaneController;
use App\Http\Controllers\UserMuteController;
use App\Http\Controllers\UserOpenGraphImageController;
use App\Http\Controllers\UserPasteisModeController;
use App\Http\Controllers\UserPreviewController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserSearchController;
use App\Http\Controllers\UserSitemapController;
use App\Http\Controllers\UserTimezoneController;
use App\Http\Middleware\RememberIntendedUrl;
use App\Http\Middleware\SignInLocalDeveloper;
use App\Http\Middleware\ThrottleCommits;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Commit from the terminal: curl -fsSL https://dev-null.sh/commit | bash -s -- 'Hello world!'
Route::get('commit', [ScriptController::class, 'show'])->name('cli-script.show');

Route::middleware('guest')->group(function (): void {
    // Guests land straight on the Trending log...
    Route::get('/', [LogController::class, 'index'])
        ->middleware(SignInLocalDeveloper::class)
        ->name('home');

    // Session...
    Route::get('login', [SessionController::class, 'create'])
        ->name('login');
    Route::get('auth/github/callback', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

// Log, where guests read Trending and Latest...
Route::get('log/everyone', fn () => to_route('log.show', Log::Trending, 301));
Route::get('log/{log}', [LogController::class, 'show'])->name('log.show');

// Terms and privacy, readable by everyone...
Route::get('terms', [TermsController::class, 'show'])->name('terms.show');
Route::get('privacy', [PrivacyController::class, 'show'])->name('privacy.show');

// Public pages, readable by guests and crawlers...
Route::middleware(RememberIntendedUrl::class)->group(function (): void {
    Route::get('@{user:username}', [UserController::class, 'show'])->name('user.show');
    Route::get('@{user:username}/following', [UserFollowingController::class, 'index'])
        ->name('user-following.index');
    Route::get('@{user:username}/followers', [UserFollowersController::class, 'index'])
        ->name('user-followers.index');
    Route::get('@{user:username}/commits/{commit}', [CommitController::class, 'show'])
        ->scopeBindings()
        ->name('commit.show');
    Route::get('@{user:username}/commits/{commit}/photos/{photo}', [CommitPhotoController::class, 'show'])
        ->scopeBindings()
        ->whereNumber('photo')
        ->name('commit-photo.show');
});

// Sitemaps...
Route::middleware('cache.headers:public;max_age=3600;etag')->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('sitemaps/users-{page}.xml', [UserSitemapController::class, 'show'])
        ->where('page', '[1-9][0-9]*')
        ->name('user-sitemap.show');
    Route::get('sitemaps/commits-{page}.xml', [CommitSitemapController::class, 'show'])
        ->where('page', '[1-9][0-9]*')
        ->name('commit-sitemap.show');
});

// User...
Route::get('@{user:username}/preview', [UserPreviewController::class, 'show'])
    ->middleware('throttle:people')
    ->name('user-preview.show');
Route::get('@{user:username}/og.png', [UserOpenGraphImageController::class, 'show'])
    ->name('user-open-graph-image.show');
Route::get('@{user:username}/feed.atom', [UserFeedController::class, 'show'])
    ->middleware('cache.headers:public;max_age=300;etag')
    ->name('user-feed.show');

// User activity graph, embedded in READMEs...
Route::get('@{user:username}/graph.svg', [UserActivityGraphController::class, 'show'])
    ->middleware(['throttle:activity-graph', 'cache.headers:public;max_age=3600;etag'])
    ->name('user-activity-graph.show');

// Commit...
Route::get('@{user:username}/commits/{commit}/og.png', [CommitOpenGraphImageController::class, 'show'])
    ->scopeBindings()
    ->name('commit-open-graph-image.show');
Route::get('commit-images/{commitImage}', [CommitImageController::class, 'show'])
    ->whereUuid('commitImage')
    ->name('commit-image.show');
Route::get('commits/{commit}/link-preview', [CommitLinkPreviewController::class, 'show'])
    ->name('commit-link-preview.show');
Route::get('commits/{commit}/replies', [CommitReplyController::class, 'index'])
    ->withTrashed()
    ->name('commit-reply.index');

// Search...
Route::get('search', [SearchController::class, 'index'])
    ->name('search.index');

Route::get('issue-images/{image}', [IssueImageController::class, 'show'])
    ->where('image', '[a-f0-9-]+\.webp')
    ->name('issue-image.show');

// User Search...
Route::get('search/users', [UserSearchController::class, 'index'])
    ->middleware('throttle:people')
    ->name('user-search.index');

// Commit Search...
Route::get('search/commits', [CommitSearchController::class, 'index'])
    ->middleware('throttle:search')
    ->name('commit-search.index');

Route::middleware('auth')->group(function (): void {
    Route::get('settings/github-issues/authorize', [GitHubIssueAuthorizationController::class, 'create'])
        ->middleware('throttle:10,1')
        ->name('github-issue-authorization.create');
    Route::get('auth/github/issues/callback', [GitHubIssueAuthorizationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('github-issue-authorization.store');
    Route::delete('settings/github-issues', [GitHubIssueAuthorizationController::class, 'destroy'])
        ->name('github-issue-authorization.destroy');

    Route::post('issues', [IssueController::class, 'store'])
        ->middleware('throttle:issues')
        ->name('issue.store');

    // Session...
    Route::post('logout', [SessionController::class, 'destroy'])
        ->name('logout');

    // Onboarding...
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');

    // Private saved commits...
    Route::get('saved', [BookmarkController::class, 'index'])->name('bookmark.index');
    Route::put('commits/{commit}/bookmark', [BookmarkController::class, 'store'])
        ->middleware('throttle:bookmarks')
        ->name('bookmark.store');
    Route::delete('commits/{commit}/bookmark', [BookmarkController::class, 'destroy'])
        ->middleware('throttle:bookmarks')
        ->name('bookmark.destroy');

    // Commit...
    Route::get('scheduled-commits', [ScheduledCommitController::class, 'index'])->name('scheduled-commit.index');
    Route::post('scheduled-commits', [ScheduledCommitController::class, 'store'])
        ->middleware(ThrottleCommits::class)->name('scheduled-commit.store');
    Route::delete('scheduled-commits/{scheduledCommit}', [ScheduledCommitController::class, 'destroy'])->name('scheduled-commit.destroy');

    Route::get('commit-stars', [CommitStarCountController::class, 'index'])->name('commit-star-count.index');
    Route::post('commits', [CommitController::class, 'store'])
        ->middleware(ThrottleCommits::class)
        ->name('commit.store');
    Route::post('commits/preview', [CommitPreviewController::class, 'store'])
        ->name('commit-preview.store');
    Route::delete('commits/{commit}', [CommitController::class, 'destroy'])
        ->name('commit.destroy');
    Route::post('commits/{commit}/restoration', [CommitRestorationController::class, 'store'])
        ->withTrashed()
        ->name('commit-restoration.store');
    Route::put('commits/{commit}/pin', [CommitPinController::class, 'update'])
        ->middleware('throttle:pins')
        ->name('commit-pin.update');
    Route::delete('commits/{commit}/pin', [CommitPinController::class, 'destroy'])
        ->middleware('throttle:pins')
        ->name('commit-pin.destroy');
    Route::post('commits/{commit}/star', [CommitStarController::class, 'store'])
        ->middleware('throttle:stars')
        ->name('commit-star.store');
    Route::post('commits/{commit}/pasteis', [CommitPastelController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('commit-pastel.store');
    Route::post('commits/{commit}/reports', [CommitReportController::class, 'store'])
        ->middleware('throttle:commit-reports')
        ->name('commit-report.store');
    Route::delete('commits/{commit}/star', [CommitStarController::class, 'destroy'])
        ->middleware('throttle:stars')
        ->name('commit-star.destroy');
    Route::post('commits/{commit}/replies', [CommitReplyController::class, 'store'])
        ->middleware(ThrottleCommits::class)
        ->name('commit-reply.store');
    Route::delete('commits/{commit}/replies/{reply}', [CommitReplyController::class, 'destroy'])
        ->withTrashed()
        ->name('commit-reply.destroy');
    Route::post('commits/{commit}/poll/votes', [CommitPollVoteController::class, 'store'])
        ->name('commit-poll-vote.store');

    // Notifications...
    Route::get('notifications', [NotificationController::class, 'index'])->name('notification.index');
    Route::get('notifications/{notification}/group', [NotificationGroupController::class, 'show'])->name('notification-group.show');

    // Moderation...
    Route::get('moderation', [ModerationController::class, 'show'])->name('moderation.show');
    Route::get('moderation/reports', [ReportedCommitController::class, 'index'])->name('reported-commit.index');
    Route::delete('moderation/reports/{report}', [CommitReportController::class, 'destroy'])->name('commit-report.destroy');
    Route::post('moderation/{commit}/approval', [CommitApprovalController::class, 'store'])
        ->name('commit-approval.store');
    Route::post('moderation/{commit}/rejection', [CommitRejectionController::class, 'store'])
        ->name('commit-rejection.store');
    Route::put('moderation', [ModerationController::class, 'update'])->name('moderation.update');

    // Operations...
    Route::get('operations', [OperationsController::class, 'show'])->name('operations.show');
    Route::post('operations/failed-jobs/{failedJob}/retry', [FailedJobRetryController::class, 'store'])
        ->whereUuid('failedJob')
        ->name('failed-job-retry.store');
    Route::delete('operations/failed-jobs/{failedJob}', [FailedJobController::class, 'destroy'])
        ->whereUuid('failedJob')
        ->name('failed-job.destroy');

    // Mention Suggestions...
    Route::get('search/mentions', [MentionSuggestionController::class, 'index'])
        ->middleware('throttle:people')
        ->name('mention-suggestion.index');

    // User Profile...
    Route::get('settings', fn () => to_route('user-profile.edit'));
    Route::get('settings/profile', [UserProfileController::class, 'edit'])->name('user-profile.edit');
    Route::put('settings/profile', [UserProfileController::class, 'update'])
        ->middleware('throttle:profile-syncs')
        ->name('user-profile.update');
    Route::get('settings/export', ExportController::class)
        ->middleware(['throttle:exports', 'throttle:5,1'])
        ->name('export');

    // User Lane...
    Route::put('settings/lane', [UserLaneController::class, 'update'])->name('user-lane.update');

    // User Timezone...
    Route::put('settings/timezone', [UserTimezoneController::class, 'update'])->name('user-timezone.update');

    Route::put('settings/pasteis-mode', [UserPasteisModeController::class, 'update'])->name('user-pasteis-mode.update');

    // Appearance...
    Route::get('settings/appearance', fn () => Inertia::render('appearance/update'))->name('appearance.edit');

    // Terminal...
    Route::get('settings/terminal', fn () => Inertia::render('terminal/show', ['url' => url('/')]))->name('terminal.show');

    // Safety...
    Route::get('settings/safety', [SafetyController::class, 'edit'])->name('safety.edit');

    // User...
    Route::delete('user', [UserController::class, 'destroy'])->name('user.destroy');

    // User Follow...
    Route::post('@{user:username}/follow', [UserFollowController::class, 'store'])
        ->middleware('throttle:follows')
        ->name('user-follow.store');
    Route::delete('@{user:username}/follow', [UserFollowController::class, 'destroy'])
        ->middleware('throttle:follows')
        ->name('user-follow.destroy');

    // User Mute...
    Route::post('@{user:username}/mute', [UserMuteController::class, 'store'])
        ->name('user-mute.store');
    Route::delete('@{user:username}/mute', [UserMuteController::class, 'destroy'])
        ->name('user-mute.destroy');

    // User Block...
    Route::post('@{user:username}/block', [UserBlockController::class, 'store'])
        ->name('user-block.store');
    Route::delete('@{user:username}/block', [UserBlockController::class, 'destroy'])
        ->name('user-block.destroy');
});

Route::fallback(fn () => abort(404));
