<?php

declare(strict_types=1);

use App\Models\KnowledgeBaseSection;
use App\Queries\KnowledgeBaseSearchQuery;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['services.openai.key' => 'sk-test', 'ai.providers.openai.key' => 'sk-test']);

    $disk = Storage::fake('local');
    $this->source = Str::after($disk->path('knowledge.md'), base_path().'/');
});

it('stores sections and their vectors with heading context and can retrieve the nearest section', function (): void {
    Storage::disk('local')->put('knowledge.md', "# Knowledge base\n\n## Lore\n\n### Tom\nA fictional profile.\n\n### Pastéis\nPortuguese pastries.");
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push(embeddingsResponse([embeddingVector(2), embeddingVector(7)]))
        ->push(embeddingsResponse([embeddingVector(7)]))]);

    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 2 knowledge base sections')
        ->assertSuccessful();

    $sections = KnowledgeBaseSection::query()->orderBy('position')->get();
    expect($sections)->toHaveCount(2);
    expect($sections[0])->source->toBe($this->source)
        ->position->toBe(0)
        ->headings->toBe(['Knowledge base', 'Lore', 'Tom'])
        ->content->toBe('A fictional profile.')
        ->embedding->toBe(embeddingVector(2));
    expect($sections[1])->position->toBe(1)
        ->headings->toBe(['Knowledge base', 'Lore', 'Pastéis'])
        ->content->toBe('Portuguese pastries.')
        ->embedding->toBe(embeddingVector(7));

    expect(resolve(KnowledgeBaseSearchQuery::class)->get('Portuguese pastry', limit: 1)->sole()->id)
        ->toBe($sections[1]->id);

    Http::assertSent(fn (Request $request): bool => $request['input'] === [
        "Knowledge base > Lore > Tom\n\nA fictional profile.",
        "Knowledge base > Lore > Pastéis\n\nPortuguese pastries.",
    ]);
});

it('replaces changed and removed sections without duplicating them or touching other sources', function (): void {
    $other = KnowledgeBaseSection::factory()->create();
    Storage::disk('local')->put('knowledge.md', "# Lore\nOld introduction.\n\n## Tom\nOld purpose.");
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push(embeddingsResponse([embeddingVector(0), embeddingVector(1)]))
        ->push(embeddingsResponse([embeddingVector(3)]))]);
    $this->artisan('knowledge-base:embed', ['source' => $this->source])->assertSuccessful();

    Storage::disk('local')->put('knowledge.md', "# Lore\n## Tom\nDeepSeek is planned.");
    $this->artisan('knowledge-base:embed', ['source' => $this->source])->assertSuccessful();
    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 0 knowledge base sections')
        ->assertSuccessful();

    $this->assertDatabaseCount('knowledge_base_sections', 2);
    $section = KnowledgeBaseSection::query()->where('source', $this->source)->sole();
    expect($section)->position->toBe(0)
        ->headings->toBe(['Lore', 'Tom'])
        ->content->toBe('DeepSeek is planned.')
        ->embedding->toBe(embeddingVector(3));
    expect($other->fresh()->getAttributes())->toEqual($other->getAttributes());
    Http::assertSentCount(2);
});

it('preserves existing sections if embedding or storing a replacement fails', function (Closure $response, string $exception): void {
    $existing = KnowledgeBaseSection::factory()->create(['source' => $this->source]);
    Storage::disk('local')->put('knowledge.md', "# Lore\n## Tom\nUpdated text.\n## Pastéis\nMore text.");
    Http::fake(['api.openai.com/v1/embeddings' => $response()]);

    expect(fn () => $this->artisan('knowledge-base:embed', ['source' => $this->source])->run())->toThrow($exception);

    $this->assertDatabaseCount('knowledge_base_sections', 1);
    expect($existing->fresh()->getAttributes())->toEqual($existing->getAttributes());
})->with([
    'provider failure' => [fn () => Http::response([], 503), RequestException::class],
    'missing embedding' => [fn () => Http::response(embeddingsResponse([embeddingVector(0)])), UnexpectedValueException::class],
    'invalid vector after a valid insert' => [fn () => Http::response(embeddingsResponse([embeddingVector(0), [0.5, 0.5]])), QueryException::class],
]);

it('removes obsolete sections when the file has no content to embed', function (): void {
    KnowledgeBaseSection::factory()->create(['source' => $this->source]);
    $other = KnowledgeBaseSection::factory()->create();
    Storage::disk('local')->put('knowledge.md', "# Knowledge base\n## Lore\n");

    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 0 knowledge base sections')
        ->assertSuccessful();

    $this->assertDatabaseCount('knowledge_base_sections', 1);
    $this->assertModelExists($other);
    Http::assertNothingSent();
});

it('does not remove sections when the source file is missing', function (): void {
    $existing = KnowledgeBaseSection::factory()->create(['source' => $this->source]);

    expect(fn () => $this->artisan('knowledge-base:embed', ['source' => $this->source])->run())->toThrow(FileNotFoundException::class);

    $this->assertModelExists($existing);
    Http::assertNothingSent();
});

it('keeps section positions and vectors aligned across embedding batches', function (): void {
    Storage::disk('local')->put('knowledge.md', implode("\n\n", array_map(
        fn (int $position): string => "# Section {$position}\nBody {$position}.",
        range(0, 100),
    )));
    Http::fake(['api.openai.com/v1/embeddings' => Http::sequence()
        ->push(embeddingsResponse(array_fill(0, 100, embeddingVector(1))))
        ->push(embeddingsResponse([embeddingVector(2)]))]);

    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 101 knowledge base sections')
        ->assertSuccessful();

    $this->assertDatabaseCount('knowledge_base_sections', 101);
    expect(KnowledgeBaseSection::query()->where('position', 99)->sole()->embedding)->toBe(embeddingVector(1));
    expect(KnowledgeBaseSection::query()->where('position', 100)->sole())
        ->headings->toBe(['Section 100'])
        ->content->toBe('Body 100.')
        ->embedding->toBe(embeddingVector(2));
});

it('only embeds edited and added sections while keeping reused vectors aligned', function (): void {
    $first = KnowledgeBaseSection::factory()->create([
        'source' => $this->source, 'position' => 0, 'headings' => ['Lore', 'Tom'],
        'content' => 'A fictional profile.', 'embedding' => embeddingVector(1),
    ]);
    $edited = KnowledgeBaseSection::factory()->create([
        'source' => $this->source, 'position' => 1, 'headings' => ['Lore', 'Pastéis'],
        'content' => 'Old pastry story.', 'embedding' => embeddingVector(2),
    ]);
    KnowledgeBaseSection::factory()->create([
        'source' => $this->source, 'position' => 2, 'headings' => ['Stack'],
        'content' => 'PostgreSQL.', 'embedding' => embeddingVector(3),
    ]);
    Storage::disk('local')->put('knowledge.md', "# Lore\n## Tom\nA fictional profile.\n## Pastéis\nFernando brought pastries.\n# Stack\nPostgreSQL.\n# Hosting\nLaravel Cloud.");
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(4), embeddingVector(5)]))]);

    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 2 knowledge base sections')
        ->assertSuccessful();

    $this->assertDatabaseCount('knowledge_base_sections', 4);
    expect($first->fresh()->getAttributes())->toEqual($first->getAttributes());
    expect($edited->refresh())->content->toBe('Fernando brought pastries.')->embedding->toBe(embeddingVector(4));
    expect(KnowledgeBaseSection::query()->orderBy('position')->get()->pluck('embedding')->all())
        ->toBe([embeddingVector(1), embeddingVector(4), embeddingVector(3), embeddingVector(5)]);
    Http::assertSent(fn (Request $request): bool => $request['input'] === [
        "Lore > Pastéis\n\nFernando brought pastries.", "Hosting\n\nLaravel Cloud.",
    ]);
});

it('reuses moved sections but reembeds when their heading context changes', function (): void {
    KnowledgeBaseSection::factory()->create([
        'source' => $this->source, 'position' => 0, 'headings' => ['Lore', 'Tom'],
        'content' => 'A fictional profile.', 'embedding' => embeddingVector(1),
    ]);
    KnowledgeBaseSection::factory()->create([
        'source' => $this->source, 'position' => 1, 'headings' => ['Stack'],
        'content' => 'PostgreSQL.', 'embedding' => embeddingVector(2),
    ]);
    Storage::disk('local')->put('knowledge.md', "# Stack\nPostgreSQL.\n# Characters\n## Tom\nA fictional profile.");
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(3)]))]);

    $this->artisan('knowledge-base:embed', ['source' => $this->source])
        ->expectsOutputToContain('Embedded 1 knowledge base sections')
        ->assertSuccessful();

    expect(KnowledgeBaseSection::query()->orderBy('position')->get()->pluck('embedding')->all())
        ->toBe([embeddingVector(2), embeddingVector(3)]);
    Http::assertSent(fn (Request $request): bool => $request['input'] === ["Characters > Tom\n\nA fictional profile."]);
});

it('refuses to import content from a different release', function (): void {
    $existing = KnowledgeBaseSection::factory()->create(['source' => $this->source]);
    Storage::disk('local')->put('knowledge.md', "# Lore\nOld release.");

    $this->artisan('knowledge-base:embed', [
        'source' => $this->source,
        '--expected-hash' => hash('sha256', "# Lore\nNew release."),
    ])->expectsOutputToContain('No embeddings were changed.')->assertFailed();

    expect($existing->fresh()->getAttributes())->toEqual($existing->getAttributes());
    Http::assertNothingSent();
});

it('imports the release that matches the expected file hash', function (): void {
    Storage::disk('local')->put('knowledge.md', "# Lore\nTom.");
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(embeddingsResponse([embeddingVector(1)]))]);

    $this->artisan('knowledge-base:embed', [
        'source' => $this->source,
        '--expected-hash' => hash('sha256', "# Lore\nTom."),
    ])->assertSuccessful();

    expect(KnowledgeBaseSection::query()->sole())->content->toBe('Tom.')->embedding->toBe(embeddingVector(1));
});
