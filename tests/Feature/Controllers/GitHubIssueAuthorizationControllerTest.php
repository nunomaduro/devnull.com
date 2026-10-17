<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GitHubUser;

beforeEach(function (): void {
    config(['services.github.client_id' => 'oauth-client', 'services.github.client_secret' => 'oauth-test-secret']);
});

it('requests public_repo only from settings with state and PKCE protection', function (): void {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('github-issue-authorization.create'));

    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'scope' => 'public_repo',
        'redirect_uri' => route('github-issue-authorization.store'),
        'code_challenge_method' => 'S256',
    ]);
    expect($query['state'])->not->toBeEmpty();
    expect($query['code_challenge'])->not->toBeEmpty();
    $response->assertSessionHas('github_issue_authorization_user', $user->id);
});

it('stores an encrypted token only for the current github account with a granted repo scope', function (string $scope): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    Socialite::fake('github-issues', GitHubUser::fake([
        'id' => $user->github_id,
        'token' => 'user-owned-token',
        'approvedScopes' => [$scope],
        'expiresIn' => 28800,
    ]));

    $this->actingAs($user)->withSession(['github_issue_authorization_user' => $user->id])
        ->get(route('github-issue-authorization.store', ['code' => 'authorization-code']))
        ->assertRedirectToRoute('user-profile.edit')->assertInertiaFlash('toast.type', 'success');

    expect($user->refresh()->github_issue_token)->toBe('user-owned-token');
    expect($user->github_issue_token_expires_at->equalTo(now()->addHours(8)))->toBeTrue();
    expect(DB::table('users')->where('id', $user->id)->value('github_issue_token'))->not->toContain('user-owned-token');
    expect($user->toArray())->not->toHaveKeys(['github_issue_token', 'github_issue_token_expires_at']);

    $this->get(route('user-profile.edit'))->assertInertia(fn ($page) => $page
        ->where('auth.githubIssueCommandsEnabled', true)
        ->missing('auth.user.github_issue_token')
        ->missing('auth.user.github_issue_token_expires_at'));
    $this->assertAuthenticatedAs($user);

    $this->travel(8)->hours();
    $this->get(route('user-profile.edit'))->assertInertia(fn ($page) => $page->where('auth.githubIssueCommandsEnabled', false));
})->with(['public_repo', 'repo']);

it('rejects a different account or insufficient scope without changing the user', function (bool $sameAccount, array $scopes): void {
    $user = User::factory()->create();
    Socialite::fake('github-issues', GitHubUser::fake([
        'id' => $sameAccount ? $user->github_id : 'different-github-account',
        'token' => 'untrusted-token',
        'approvedScopes' => $scopes,
    ]));

    $this->actingAs($user)->withSession(['github_issue_authorization_user' => $user->id])
        ->get(route('github-issue-authorization.store', ['code' => 'authorization-code']))
        ->assertRedirectToRoute('user-profile.edit')->assertInertiaFlash('toast.type', 'error');

    expect($user->refresh()->github_issue_token)->toBeNull();
    $this->assertAuthenticatedAs($user);
})->with([
    'different account' => [false, ['public_repo']],
    'email only' => [true, ['user:email']],
    'no permissions' => [true, []],
]);

it('rejects an invalid oauth state and handles cancellation without granting access', function (bool $cancelled): void {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['github_issue_authorization_user' => $user->id, 'state' => 'expected-state'])
        ->get(route('github-issue-authorization.store', $cancelled
            ? ['error' => 'access_denied']
            : ['code' => 'authorization-code', 'state' => 'forged-state']))
        ->assertRedirectToRoute('user-profile.edit')->assertInertiaFlash('toast.type', 'error');

    expect($user->refresh()->github_issue_token)->toBeNull();
})->with([true, false]);

it('does not attach a grant to a different local session user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Socialite::fake('github-issues', GitHubUser::fake(['id' => $user->github_id, 'approvedScopes' => ['public_repo']]));

    $this->actingAs($user)->withSession(['github_issue_authorization_user' => $other->id])
        ->get(route('github-issue-authorization.store', ['code' => 'authorization-code']))
        ->assertRedirectToRoute('user-profile.edit')->assertInertiaFlash('toast.type', 'error');

    expect($user->refresh()->github_issue_token)->toBeNull();
    expect($other->refresh()->github_issue_token)->toBeNull();
});

it('disables issue commands and erases only the current users stored token', function (): void {
    $user = User::factory()->withGitHubIssueAuthorization()->create();
    $other = User::factory()->withGitHubIssueAuthorization()->create();

    $this->actingAs($user)->delete(route('github-issue-authorization.destroy'))
        ->assertRedirectToRoute('user-profile.edit');

    expect($user->refresh()->github_issue_token)->toBeNull();
    expect($other->refresh()->github_issue_token)->toBe('issue-test-token');
    $this->get(route('user-profile.edit'))->assertInertia(fn ($page) => $page->where('auth.githubIssueCommandsEnabled', false));
});

it('requires login for the permission flow', function (): void {
    $this->get(route('github-issue-authorization.create'))->assertRedirectToRoute('home');
    $this->get(route('github-issue-authorization.store'))->assertRedirectToRoute('home');
    $this->delete(route('github-issue-authorization.destroy'))->assertRedirectToRoute('home');
});
