<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A check of a draft: the request body of a save and the definition of a read. Documents the
 * contract only; the publisher's item rules validate it.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(
    description: 'Total = dice + modifier + situational modifier; the outcome is the first band whose bound is at least the total.',
    required: ['key', 'label', 'dice', 'bands'],
)]
final readonly class GameSystemCheck
{
    /**
     * @param list<GameSystemCheckBand> $bands
     */
    public function __construct(
        #[OA\Property(description: 'Unique among the draft\'s checks: a-z, 0-9 and "-".', pattern: '^[a-z0-9-]+$', example: 'skill')]
        public string $key,
        #[OA\Property(example: 'Skill check', maxLength: 100)]
        public string $label,
        #[OA\Property(description: 'Shown to the player when they roll.', maxLength: 500)]
        public ?string $hint,
        #[OA\Property(description: 'Dice notation, at most 100 characters.', example: '1d10')]
        public string $dice,
        #[OA\Property(description: 'A formula over the sheet and trackers (1 to 200 characters) added to the roll.', example: '{hands}+{edge}')]
        public ?string $modifier,
        #[OA\Property(ref: new Model(type: GameSystemCheckDifficulty::class), description: 'Absent or null when the check has no difficulty.', nullable: true)]
        public ?GameSystemCheckDifficulty $difficulty,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: GameSystemCheckBand::class)), maxItems: 10, minItems: 1)]
        public array $bands,
    ) {
    }
}
