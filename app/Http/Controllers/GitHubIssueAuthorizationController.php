<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateUserGitHubAuthorization;
use App\Http\Requests\CreateGitHubIssueAuthorizationRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

final readonly class GitHubIssueAuthorizationController
{
    public function create(Request $request, #[CurrentUser] User $user): SymfonyRedirectResponse
    {
        $request->session()->put('github_issue_authorization_user', $user->id);

        return Socialite::driver('github-issues')->redirect();
    }

    public function store(CreateGitHubIssueAuthorizationRequest $request, #[CurrentUser] User $user, UpdateUserGitHubAuthorization $action): RedirectResponse
    {
        $githubUser = $request->githubUser();
        $action->handle($user, $githubUser->token, $githubUser->expiresIn === null ? null : now()->addSeconds($githubUser->expiresIn));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'GitHub issue commands enabled. Issues will be created as @'.$user->username.'.',
        ]);

        return to_route('user-profile.edit');
    }

    public function destroy(#[CurrentUser] User $user, UpdateUserGitHubAuthorization $action): RedirectResponse
    {
        $action->handle($user, null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Issue commands disabled and the stored token removed. You can also revoke the grant in GitHub settings.',
        ]);

        return to_route('user-profile.edit');
    }
}
