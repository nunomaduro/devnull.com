<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\KnowledgeBaseSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $id
 * @property-read string $source
 * @property-read int $position
 * @property-read list<string> $headings
 * @property-read string $content
 * @property-read list<float> $embedding
 * @property-read float|null $distance
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['embedding'])]
final class KnowledgeBaseSection extends Model
{
    /** @use HasFactory<KnowledgeBaseSectionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'source' => 'string',
            'position' => 'integer',
            'headings' => 'array',
            'content' => 'string',
            'embedding' => AsVector::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
