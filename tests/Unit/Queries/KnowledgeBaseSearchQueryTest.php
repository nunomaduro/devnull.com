<?php

declare(strict_types=1);

use App\Models\KnowledgeBaseSection;
use App\Queries\CommitSearchQuery;
use App\Queries\KnowledgeBaseSearchQuery;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function (): void {
    config(['ai.providers.openai.key' => 'sk-test']);
});

it('returns sections across sources by cosine distance with their citation context', function (): void {
    Embeddings::fake([[embeddingVector(0)]])->preventStrayEmbeddings();

    $far = KnowledgeBaseSection::factory()->create(['source' => 'a.md', 'embedding' => embeddingVector(1)]);
    $near = KnowledgeBaseSection::factory()->create([
        'source' => 'KNOWLEDGE_BASE.md',
        'position' => 4,
        'headings' => ['Knowledge base', 'Lore', 'Tom'],
        'content' => 'A **fictional** profile inspired by MySpace Tom.',
        'embedding' => embeddingVector(0),
    ]);
    KnowledgeBaseSection::factory()->create(['embedding' => array_map(fn (float $value): float => -$value, embeddingVector(0))]);

    $results = resolve(KnowledgeBaseSearchQuery::class)->get('  Who is Tom?  ', limit: 2);

    expect($results->pluck('id')->all())->toBe([$near->id, $far->id]);
    expect($results->pluck('distance')->all())->toBe([0.0, 1.0]);
    expect($results->first()->toArray())->toBe([
        'id' => $near->id,
        'source' => 'KNOWLEDGE_BASE.md',
        'position' => 4,
        'headings' => ['Knowledge base', 'Lore', 'Tom'],
        'content' => 'A **fictional** profile inspired by MySpace Tom.',
        'distance' => 0.0,
    ]);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Who is Tom?']
        && $prompt->provider->name() === 'openai'
        && $prompt->model === 'text-embedding-3-small'
        && $prompt->dimensions === 1536);
    Http::assertNothingSent();
});

it('defaults to five sections and orders tied distances by source and position', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)]))]);

    KnowledgeBaseSection::factory()->create(['source' => 'z.md']);
    $sections = KnowledgeBaseSection::factory()->count(5)->sequence(
        ['position' => 4],
        ['position' => 2],
        ['position' => 0],
        ['position' => 3],
        ['position' => 1],
    )->create(['source' => 'a.md']);

    $results = resolve(KnowledgeBaseSearchQuery::class)->get('AI');

    expect($results->pluck('id')->all())->toBe([
        $sections[2]->id, $sections[4]->id, $sections[1]->id, $sections[3]->id, $sections[0]->id,
    ]);
});

it('returns nothing without embedding for an empty term or a nonpositive limit', function (string $term, int $limit): void {
    expect(resolve(KnowledgeBaseSearchQuery::class)->get($term, $limit))->toBeEmpty();

    Http::assertNothingSent();
})->with([
    'empty' => ['', 5],
    'whitespace' => [" \t\n\u{2003}", 5],
    'zero limit' => ['Tom', 0],
    'negative limit' => ['Tom', -1],
]);

it('returns no sections when the knowledge base has not been imported', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)]))]);

    expect(resolve(KnowledgeBaseSearchQuery::class)->get('Tom'))->toBeEmpty();
});

it('reuses the trimmed query embedding for an hour but always reads current sections', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)]))]);
    $section = KnowledgeBaseSection::factory()->create(['content' => 'Original lore.']);
    $query = resolve(KnowledgeBaseSearchQuery::class);

    expect($query->get(' Tom ')->sole()->content)->toBe('Original lore.');
    $section->update(['content' => 'Updated lore.']);
    expect($query->get('Tom')->sole()->content)->toBe('Updated lore.');
    Http::assertSentCount(1);

    $section->delete();
    expect($query->get('Tom'))->toBeEmpty();
    Http::assertSentCount(1);

    $this->travel(1)->hours();
    $query->get('Tom');
    Http::assertSentCount(2);
});

it('embeds different questions independently', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push(embeddingsResponse([embeddingVector(0)]))
        ->push(embeddingsResponse([embeddingVector(1)]))]);
    $tom = KnowledgeBaseSection::factory()->create(['embedding' => embeddingVector(0)]);
    $pastries = KnowledgeBaseSection::factory()->create(['embedding' => embeddingVector(1)]);
    $query = resolve(KnowledgeBaseSearchQuery::class);

    expect($query->get('Who is Tom?', limit: 1)->sole()->id)->toBe($tom->id);
    expect($query->get('What are pastéis?', limit: 1)->sole()->id)->toBe($pastries->id);
});

it('propagates embedding failures and retries instead of caching them as empty results', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push([], 503)
        ->push(embeddingsResponse([embeddingVector(0)]))]);
    $section = KnowledgeBaseSection::factory()->create();
    $query = resolve(KnowledgeBaseSearchQuery::class);

    expect(fn () => $query->get('Tom'))->toThrow(ProviderOverloadedException::class);
    expect($query->get('Tom')->sole()->id)->toBe($section->id);
});

it('shares compatible cached query embeddings with commit search', function (): void {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(0)]))]);
    $section = KnowledgeBaseSection::factory()->create();

    expect(resolve(CommitSearchQuery::class)->get('Tom', null))->toBeEmpty();
    expect(resolve(KnowledgeBaseSearchQuery::class)->get(' Tom ')->sole()->id)->toBe($section->id);

    Http::assertSentCount(1);
});
