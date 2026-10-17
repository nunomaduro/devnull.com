<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('runs the selected issue tool with the full draft and correct label, without publishing a commit', function (string $command, string $label): void {
    $user = User::factory()->withGitHubIssueAuthorization()->create();
    $body = "Search loses its filters\n\nSteps: select PHP, then navigate back.\n\n\nExpected: filters persist.";
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([
            'number' => 123,
            'html_url' => 'https://github.com/nunomaduro/dev-null.sh/issues/123',
            'labels' => [['name' => $label]],
        ], 201)]);

    $this->actingAs($user)->postJson(route('issue.store'), ['command' => $command, 'body' => $body])
        ->assertCreated()
        ->assertExactJson(['number' => 123, 'url' => 'https://github.com/nunomaduro/dev-null.sh/issues/123', 'labeled' => true]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer issue-test-token')
        && $request['title'] === 'Search loses its filters'
        && $request['body'] === $body."\n\n<!-- dev-null-command:".mb_substr($command, 1).' -->'
        && $request['labels'] === [$label]);
    Http::assertSentCount(1);
    $this->assertDatabaseCount('commits', 0);
})->with([
    'feature' => ['/feature', 'enhancement'],
    'bug' => ['/bug', 'bug'],
]);

it('includes every image as a permanent signed link accessible to GitHub but not by guessing a private path', function (): void {
    Storage::fake();
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([
            'number' => 124, 'html_url' => 'https://github.com/nunomaduro/dev-null.sh/issues/124', 'labels' => [['name' => 'bug']],
        ], 201)]);

    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create())->postJson(route('issue.store'), [
        'command' => '/bug', 'body' => '',
        'images' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.jpg')],
        'image_descriptions' => ['Before "fix" <script>', 'After'],
    ])->assertCreated();

    $request = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->sole()[0];
    expect($request['title'])->toBe('Bug report');
    expect($request['body'])->toContain('alt="Before &quot;fix&quot; &lt;script&gt;"', 'alt="After"');
    preg_match_all('/src="([^"]+)"/', $request['body'], $matches);
    expect($matches[1])->toHaveCount(2);
    auth()->logout();
    $this->travel(2)->years();

    foreach ($matches[1] as $url) {
        $url = html_entity_decode($url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(strtok($url, '?'))->assertNotFound();
        $this->get($url.'tampered')->assertNotFound();
    }

    expect(Storage::allFiles('commit-images'))->toHaveCount(2);
    $this->assertDatabaseCount('commits', 0);
});

it('rejects invalid command drafts before calling GitHub', function (array $data, string $field): void {
    Http::fake();

    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create())->postJson(route('issue.store'), $data)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'unknown command' => [['command' => '/delete', 'body' => 'An issue'], 'command'],
    'empty draft' => [['command' => '/bug'], 'body'],
    'too long' => [['command' => '/bug', 'body' => str_repeat('a', 4001)], 'body'],
    'poll' => [['command' => '/bug', 'body' => 'An issue', 'poll' => ['options' => ['Yes', 'No'], 'duration' => 60]], 'poll'],
    'unsafe upload' => [fn (): array => ['command' => '/bug', 'images' => [UploadedFile::fake()->create('payload.svg', 1, 'image/svg+xml')]], 'images.0'],
]);

it('requires authentication and the users own valid authorization', function (): void {
    Http::fake();
    $data = ['command' => '/bug', 'body' => 'An issue'];

    $this->postJson(route('issue.store'), $data)->assertUnauthorized();
    User::factory()->withGitHubIssueAuthorization()->create();
    $this->actingAs(User::factory()->create())->postJson(route('issue.store'), $data)->assertForbidden();
    $expired = User::factory()->withGitHubIssueAuthorization()->create(['github_issue_token_expires_at' => now()]);
    $this->actingAs($expired)->postJson(route('issue.store'), $data)->assertForbidden();

    Http::assertNothingSent();
});

it('reports missing labels instead of claiming a fully successful command', function (): void {
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([
            'number' => 125, 'html_url' => 'https://github.com/nunomaduro/dev-null.sh/issues/125', 'labels' => [],
        ], 201)]);

    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create())->postJson(route('issue.store'), ['command' => '/feature', 'body' => 'A feature'])
        ->assertCreated()->assertJsonPath('labeled', false);

    Http::assertSentCount(1);
});

it('does not retry a failed create and cleans up images only when GitHub definitively rejects it', function (int $status): void {
    Storage::fake();
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([], $status),
    ]);

    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create())->postJson(route('issue.store'), [
        'command' => '/bug', 'body' => 'An issue', 'images' => [UploadedFile::fake()->image('error.png')],
    ])->assertStatus(502)->assertJsonPath('message', 'GitHub did not confirm the issue. Your draft is safe. Check the upstream issues before trying again.');

    Http::assertSentCount(1);
    expect(Storage::allFiles('commit-images'))->toHaveCount($status === 422 ? 0 : 1);
})->with([422, 500]);

it('handles an unknown outcome after a connection failure without retrying', function (): void {
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::failedConnection(),
    ]);

    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create())->postJson(route('issue.store'), ['command' => '/bug', 'body' => 'An issue'])
        ->assertStatus(502);
});

it('limits issue creation per user', function (): void {
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([
            'number' => 126, 'html_url' => 'https://github.com/nunomaduro/dev-null.sh/issues/126', 'labels' => [['name' => 'bug']],
        ], 201)]);
    $this->actingAs(User::factory()->withGitHubIssueAuthorization()->create());

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('issue.store'), ['command' => '/bug', 'body' => "Issue {$attempt}"])->assertCreated();
    }
    $this->postJson(route('issue.store'), ['command' => '/bug', 'body' => 'One too many'])->assertTooManyRequests();

    Http::assertSentCount(5);
});

it('uses each users token rather than another users authorization', function (): void {
    Http::fake([
        'api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([
            'number' => 127, 'html_url' => 'https://github.com/nunomaduro/dev-null.sh/issues/127', 'labels' => [],
        ], 201),
    ]);
    $first = User::factory()->withGitHubIssueAuthorization()->create();
    $second = User::factory()->withGitHubIssueAuthorization()->create(['github_issue_token' => 'another-user-token']);

    $this->actingAs($first)->postJson(route('issue.store'), ['command' => '/bug', 'body' => 'First issue'])->assertCreated();
    $this->actingAs($second)->postJson(route('issue.store'), ['command' => '/feature', 'body' => 'Second issue'])->assertCreated();

    Http::assertSent(fn (Request $request): bool => $request['title'] === 'First issue' && $request->hasHeader('Authorization', 'Bearer issue-test-token'));
    Http::assertSent(fn (Request $request): bool => $request['title'] === 'Second issue' && $request->hasHeader('Authorization', 'Bearer another-user-token'));
    Http::assertSentCount(2);
});

it('disables revoked credentials without retrying or losing the draft', function (): void {
    Http::fake(['api.github.com/repos/nunomaduro/dev-null.sh/issues' => Http::response([], 401)]);
    $user = User::factory()->withGitHubIssueAuthorization()->create();

    $this->actingAs($user)->postJson(route('issue.store'), ['command' => '/bug', 'body' => 'Keep this draft'])
        ->assertForbidden();

    expect($user->refresh()->github_issue_token)->toBeNull();
    $this->get(route('user-profile.edit'))->assertInertia(fn ($page) => $page->where('auth.githubIssueCommandsEnabled', false));
    Http::assertSentCount(1);
    $this->assertDatabaseCount('commits', 0);
});
