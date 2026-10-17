<?php

declare(strict_types=1);

use App\Models\User;

it('suggests the people the user follows without a term, most recently followed first', function (): void {
    $user = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    User::factory()->create();

    $user->following()->attach($first, ['created_at' => now()->subMinute()]);
    $user->following()->attach($second, ['created_at' => now()]);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=');

    $response->assertOk()
        ->assertJsonCount(2, 'users')
        ->assertJsonPath('users.0.id', $second->id)
        ->assertJsonPath('users.1.id', $first->id)
        ->assertJsonMissingPath('users.0.pivot')
        ->assertJsonMissingPath('users.0.email');
});

it('suggests matches, people the user follows first', function (): void {
    $user = User::factory()->create(['username' => 'viewer', 'name' => 'Viewer']);
    User::factory()->create(['username' => 'taylor', 'name' => 'Taylor']);
    User::factory()->create(['username' => 'taylorbot', 'name' => 'Bot']);
    $followed = User::factory()->create(['username' => 'taylorotwell', 'name' => 'Taylor Otwell']);
    User::factory()->create(['username' => 'jess', 'name' => 'Jess Taylor']);

    $user->following()->attach($followed);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=@tay');

    $response->assertOk()
        ->assertJsonCount(4, 'users')
        ->assertJsonPath('users.0.username', 'taylorotwell')
        ->assertJsonPath('users.1.username', 'taylor')
        ->assertJsonPath('users.2.username', 'taylorbot')
        ->assertJsonPath('users.3.username', 'jess');
});

it('matches literal wildcard characters in display names', function (string $name, string $query): void {
    $match = User::factory()->create(['username' => 'matching', 'name' => $name]);
    User::factory()->create(['username' => 'other', 'name' => 'JaneXDoe']);

    $this->actingAs(User::factory()->create(['username' => 'viewer']))
        ->getJson('/search/mentions?q='.rawurlencode($query))
        ->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.id', $match->id);
})->with([
    'underscore' => ['Jane_Doe', 'Jane_Doe'],
    'percent' => ['Discount 50%', '50%'],
    'escape character' => ['Bang!', 'Bang!'],
]);

it('never suggests the user themselves', function (): void {
    $user = User::factory()->create(['username' => 'nuno', 'name' => 'Nuno Maduro']);

    $response = $this->actingAs($user)->getJson('/search/mentions?q=nuno');

    $response->assertOk()
        ->assertJsonCount(0, 'users');
});

it('never suggests muted people or people across a block', function (string $term): void {
    $user = User::factory()->create(['username' => 'viewer', 'name' => 'Viewer']);
    $muted = User::factory()->create(['username' => 'taylor_muted']);
    $blocked = User::factory()->create(['username' => 'taylor_blocked']);
    $blocker = User::factory()->create(['username' => 'taylor_blocker']);
    $visible = User::factory()->create(['username' => 'taylor']);

    $user->following()->attach([$muted->id, $blocked->id, $blocker->id, $visible->id]);
    $user->muting()->attach($muted);
    $user->blocking()->attach($blocked);
    $blocker->blocking()->attach($user);

    $response = $this->actingAs($user)->getJson('/search/mentions?q='.$term);

    $response->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.id', $visible->id);
})->with(['', 'taylor']);

it('limits the number of suggestions', function (): void {
    User::factory()->count(10)->sequence(fn ($sequence): array => ['username' => "dev{$sequence->index}"])->create();

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/search/mentions?q=dev');

    $response->assertOk()
        ->assertJsonCount(6, 'users');
});

it('rejects overly long queries', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/search/mentions?q='.str_repeat('a', 101));

    $response->assertUnprocessable();
});

it('requires authentication', function (): void {
    $response = $this->getJson('/search/mentions?q=nuno');

    $response->assertUnauthorized();
});
