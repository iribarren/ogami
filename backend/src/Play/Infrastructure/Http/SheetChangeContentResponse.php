<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\SheetChangeContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a Flow step's sheet Effects changed on a Character (ADR 0027), recorded right after the
 * step's own entry.
 */
#[OA\Schema(required: ['kind', 'characterId', 'characterName', 'changes'])]
final readonly class SheetChangeContentResponse
{
    /**
     * @param list<SheetChangeResponse> $changes
     */
    private function __construct(
        #[OA\Property(enum: [SheetChangeContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The Character whose sheet changed.', format: 'uuid')]
        public string $characterId,
        #[OA\Property(description: 'The name of the Character when the step completed.', example: 'Vi')]
        public string $characterName,
        #[OA\Property(
            description: 'Every change, in the order the effects applied; at least one.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SheetChangeResponse::class)),
        )]
        public array $changes,
    ) {
    }

    public static function of(SheetChangeContent $content): self
    {
        return new self(
            SheetChangeContent::KIND,
            $content->characterId(),
            $content->characterName(),
            array_map(SheetChangeResponse::of(...), $content->changes()),
        );
    }
}
