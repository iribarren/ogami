<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A check the narrative editors may pick, with the keys of its bands.
 */
#[OA\Schema(required: ['key', 'label', 'bands', 'difficulty'])]
final readonly class NarrativeCatalogCheckResponse
{
    /**
     * @param list<string> $bands
     */
    public function __construct(
        #[OA\Property(example: 'skill')]
        public string $key,
        #[OA\Property(example: 'Skill check')]
        public string $label,
        #[OA\Property(description: 'The keys of its bands, which a check step\'s outcomes name.', type: 'array', items: new OA\Items(type: 'string'), example: ['fail', 'success'])]
        public array $bands,
        #[OA\Property(ref: new Model(type: NarrativeCatalogDifficultyResponse::class), description: 'Null when the check has no difficulty the player sets.', nullable: true)]
        public ?NarrativeCatalogDifficultyResponse $difficulty,
    ) {
    }
}
