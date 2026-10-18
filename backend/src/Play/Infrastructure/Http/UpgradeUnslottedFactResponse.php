<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\UnslottedFact;
use OpenApi\Attributes as OA;

/**
 * A slot fact whose Fact slot the target release no longer has: it becomes a free fact.
 */
#[OA\Schema(required: ['id', 'slotKey', 'label', 'text'])]
final readonly class UpgradeUnslottedFactResponse
{
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(description: 'The key of the Fact slot that is gone.', example: 'prince')]
        public string $slotKey,
        #[OA\Property(description: 'The label the free fact keeps: its own, else the old slot\'s name.', example: 'The Prince')]
        public string $label,
        #[OA\Property(description: 'The text the free fact keeps; for a subject fact, the subject\'s name.', example: 'Mithras')]
        public string $text,
    ) {
    }

    public static function fromFact(UnslottedFact $fact): self
    {
        return new self($fact->factId, $fact->slotKey, $fact->label, $fact->text);
    }
}
