<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitSearchQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\EmbeddingsCountMismatchException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function (): void {
    config(['services.openai.key' => 'sk-test', 'ai.providers.openai.key' => 'sk-test']);
});

it('returns the commits nearest in meaning, closest first', function (): void {
    fakeSearch();
    Embeddings::fake([[embeddingVector(0)]])->preventStrayEmbeddings();

    $close = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    $far = Commit::factory()->create(['embedding' => embeddingVector(1)]);

    $results = resolve(CommitSearchQuery::class)->get('a query', null);

    expect($results->pluck('id')->all())->toBe([$close->id, $far->id]);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['a query']
        && $prompt->provider->name() === 'openai'
        && $prompt->model === 'text-embedding-3-small'
        && $prompt->dimensions === 1536);
});

it('reads the term against the candidates with the decision model, keeping only the relevant ones', function (): void {
    fakeSearch([true, false]);

    $relevant = Commit::factory()->create(['body' => 'About indexing', 'embedding' => embeddingVector(0)]);
    Commit::factory()->create(['body' => 'About something else', 'embedding' => embeddingVector(1)]);

    expect(resolve(CommitSearchQuery::class)->get('indexing strategies', null)->pluck('id')->all())->toBe([$relevant->id]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/decisions')
        && $request['model'] === 'gpt-6-luna'
        && str_ends_with($request['input'], "Query: indexing strategies\n\nPosts:\n1. About indexing\n2. About something else")
        && array_column($request['questions'], 'name') === ['post_1', 'post_2']
        && array_unique(array_column($request['questions'], 'type')) === ['predicate']);
});

it('keeps a candidate unless the decision model thinks it more likely irrelevant than not', function (float $probability, bool $isKept): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)])),
        'api.openai.com/v1/decisions' => Http::response(['answers' => [
            ['type' => 'predicate', 'name' => 'post_1', 'probability' => $probability],
        ]]),
    ]);

    Commit::factory()->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', null))->toHaveCount($isKept ? 1 : 0);
})->with([
    'likely' => [0.8, true],
    'even' => [0.5, true],
    'unlikely' => [0.2, false],
]);

it('keeps every candidate when the decision model refuses', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)])),
        'api.openai.com/v1/decisions' => Http::response(['answers' => [['type' => 'refusal', 'name' => 'post_1']]]),
    ]);

    $commit = Commit::factory()->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', null)->pluck('id')->all())->toBe([$commit->id]);
});

it('leaves out commits that have not been embedded', function (): void {
    fakeSearch();

    $embedded = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    Commit::factory()->create(['embedding' => null]);

    expect(resolve(CommitSearchQuery::class)->get('a query', null)->pluck('id')->all())->toBe([$embedded->id]);
});

it('limits the number of results', function (): void {
    fakeSearch();

    Commit::factory()->count(3)->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', null, limit: 2))->toHaveCount(2);
});

it('returns nothing, and asks openai nothing, for a term shorter than three characters', function (string $term): void {
    Http::fake();

    expect(resolve(CommitSearchQuery::class)->get($term, null))->toBeEmpty();

    Http::assertNothingSent();
})->with(['empty' => ['   '], 'one letter' => ['a'], 'two letters' => ['on']]);

it('loads the commits as the feeds do, so the card renders them', function (): void {
    fakeSearch();

    $viewer = User::factory()->create();
    $commit = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    $viewer->starredCommits()->attach($commit);

    $result = resolve(CommitSearchQuery::class)->get('a query', $viewer)->sole();

    expect($result->user->is($commit->user))->toBeTrue()
        ->and($result->stars_count)->toBe(1)
        ->and($result->starred)->toBeTrue()
        ->and($result->replies_count)->toBe(0)
        ->and($result->is_saved)->toBeFalse();
});

it('leaves out commits the viewer may not see', function (): void {
    fakeSearch();

    $approved = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    Commit::factory()->pending()->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', User::factory()->create())->pluck('id')->all())->toBe([$approved->id]);
});

it('leaves out muted people and people across a block', function (): void {
    fakeSearch();

    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    $blocked = User::factory()->create();
    $blocker = User::factory()->create();

    $viewer->muting()->attach($muted);
    $viewer->blocking()->attach($blocked);
    $blocker->blocking()->attach($viewer);

    $reachable = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    Commit::factory()->for($muted)->create(['embedding' => embeddingVector(0)]);
    Commit::factory()->for($blocked)->create(['embedding' => embeddingVector(0)]);
    Commit::factory()->for($blocker)->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', $viewer)->pluck('id')->all())->toBe([$reachable->id]);
});

it('asks the decision model nothing when no commit is a candidate', function (): void {
    fakeSearch();

    expect(resolve(CommitSearchQuery::class)->get('a query', null))->toBeEmpty();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/decisions'));
});

it('keeps every candidate when the decision model answers in a shape it cannot read', function (array $body): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)])),
        'api.openai.com/v1/decisions' => Http::response($body),
    ]);

    $commit = Commit::factory()->create(['embedding' => embeddingVector(0)]);

    expect(resolve(CommitSearchQuery::class)->get('a query', null)->pluck('id')->all())->toBe([$commit->id]);
})->with([
    'no answers' => [[]],
    'answers that are not a list' => [['answers' => 'no']],
    'an answer for another post' => [['answers' => [['type' => 'predicate', 'name' => 'post_2', 'probability' => 0.0]]]],
    'a probability that is not a number' => [['answers' => [['type' => 'predicate', 'name' => 'post_1', 'probability' => 'low']]]],
]);

it('caches trimmed search embeddings for an hour without caching commit visibility', function (): void {
    fakeSearch();
    $commit = Commit::factory()->create(['embedding' => embeddingVector(0)]);
    $query = resolve(CommitSearchQuery::class);

    expect($query->get('  a query  ', null)->sole()->id)->toBe($commit->id);
    $commit->delete();
    expect($query->get('a query', null))->toBeEmpty();
    Http::assertSentCount(2);

    $this->travel(1)->hours();
    expect($query->get('a query', null))->toBeEmpty();
    Http::assertSentCount(3);
});

it('does not cache a missing query embedding as a successful search', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push(['data' => []])
        ->push(embeddingsResponse([embeddingVector(0)]))]);
    $query = resolve(CommitSearchQuery::class);

    expect(fn () => $query->get('a query', null))->toThrow(EmbeddingsCountMismatchException::class);
    expect($query->get('a query', null))->toBeEmpty();
    Http::assertSentCount(2);
});
