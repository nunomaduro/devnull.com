<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\KnowledgeBaseSection;
use App\Services\OpenAI;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

final readonly class KnowledgeBaseSearchQuery
{
    private const int CACHE_SECONDS = 3600;

    /**
     * @return Collection<int, KnowledgeBaseSection>
     */
    public function get(string $term, int $limit = 5): Collection
    {
        $term = mb_trim($term);

        if ($term === '' || $limit <= 0) {
            return new Collection();
        }

        $embedding = array_values(Embeddings::for([$term])
            ->dimensions(OpenAI::EMBEDDING_DIMENSIONS)
            ->cache(self::CACHE_SECONDS)
            ->generate(provider: Lab::OpenAI, model: OpenAI::EMBEDDING_MODEL)
            ->first());

        return KnowledgeBaseSection::query()
            ->select(['id', 'source', 'position', 'headings', 'content'])
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->withCasts(['distance' => 'float'])
            ->orderByVectorDistance('embedding', $embedding)
            ->orderBy('source')
            ->orderBy('position')
            ->limit($limit)
            ->get()
            ->toBase();
    }
}
