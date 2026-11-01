<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckChallengeBurnContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The burn part of a recorded challenge check: the momentum that was burned, the score it became, the
 * band the roll had before the burn and what the cost changed on the Character's sheet.
 */
#[OA\Schema(required: ['label', 'momentum', 'score', 'rolledBandKey', 'rolledBandLabel', 'changes'])]
final readonly class CheckChallengeBurnContentResponse
{
    /**
     * @param list<SheetChangeResponse> $changes
     */
    private function __construct(
        #[OA\Property(description: 'The cost of the burn when it was burned.', example: 'Burn momentum')]
        public string $label,
        #[OA\Property(description: 'The value of the momentum field that was burned.', example: 5)]
        public int $momentum,
        #[OA\Property(description: 'The score the challenge dice were compared against after the burn: the momentum, at most the cap.', example: 5)]
        public int $score,
        #[OA\Property(description: 'The key of the Outcome band the roll had before the burn.', example: 'weak')]
        public string $rolledBandKey,
        #[OA\Property(description: 'The label of the Outcome band the roll had before the burn.', example: 'Weak hit')]
        public string $rolledBandLabel,
        #[OA\Property(
            description: 'What the cost changed on the sheet of the rolling Character, in apply order; Tracker changes are not listed.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SheetChangeResponse::class)),
        )]
        public array $changes,
    ) {
    }

    public static function of(CheckChallengeBurnContent $burn): self
    {
        return new self($burn->label, $burn->momentum, $burn->score, $burn->rolledBandKey, $burn->rolledBandLabel, array_map(SheetChangeResponse::of(...), $burn->changes));
    }
}
