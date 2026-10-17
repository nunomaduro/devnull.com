<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GitHubUser;

final class CreateGitHubIssueAuthorizationRequest extends FormRequest
{
    private GitHubUser $githubUser;

    public function authorize(): bool
    {
        $user = $this->user();
        assert($user instanceof User);

        if ($this->session()->pull('github_issue_authorization_user') !== $user->id || $this->has('error') || ! $this->filled('code')) {
            return false;
        }

        try {
            $githubUser = Socialite::driver('github-issues')->user();
        } catch (InvalidStateException|GuzzleException) {
            return false;
        }

        assert($githubUser instanceof GitHubUser);

        if ((string) $githubUser->getId() !== $user->github_id) {
            return false;
        }

        if (! filled($githubUser->token) || (! in_array('public_repo', $githubUser->approvedScopes, true) && ! in_array('repo', $githubUser->approvedScopes, true))) {
            return false;
        }

        $this->githubUser = $githubUser;

        return true;
    }

    /** @return array{} */
    public function rules(): array
    {
        return [];
    }

    public function githubUser(): GitHubUser
    {
        return $this->githubUser;
    }

    public function failedAuthorization(): never
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => 'GitHub authorization was not completed. Use the same GitHub account and grant public_repo permission from settings.',
        ]);

        throw new HttpResponseException(to_route('user-profile.edit'));
    }
}
