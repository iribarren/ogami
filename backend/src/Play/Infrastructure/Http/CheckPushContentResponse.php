<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckPushContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The push part of a recorded pool check: the pool after the push, its successes, the band the roll
 * had before the push and what the cost changed on the Character's sheet.
 */
#[OA\Schema(required: ['label', 'dice', 'successes', 'rolledBandKey', 'rolledBandLabel', 'changes', 'special'])]
final readonly class CheckPushContentResponse
{
    /**
     * @param list<JournalPushDieResponse> $dice
     * @param list<SheetChangeResponse>    $changes
     */
    private function __construct(
        #[OA\Property(description: 'The cost of the push when it was pushed.', example: 'Take a physical Condition')]
        public string $label,
        #[OA\Property(
            description: 'Every die after the push, in roll order: the dice that succeeded keep their face, the others were re-rolled.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalPushDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'How many dice succeeded after the push; the total of the check now.', example: 3)]
        public int $successes,
        #[OA\Property(description: 'The key of the Outcome band the roll had before the push.', example: 'miss')]
        public string $rolledBandKey,
        #[OA\Property(description: 'The label of the Outcome band the roll had before the push.', example: 'Miss')]
        public string $rolledBandLabel,
        #[OA\Property(
            description: 'What the cost changed on the sheet of the rolling Character, in apply order; Tracker changes are not listed.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SheetChangeResponse::class)),
        )]
        public array $changes,
        #[OA\Property(description: 'The special result of the roll after the push; null for none, and for entries recorded before special results.', nullable: true, enum: ['critical', 'messyCritical', 'bestialFailure'])]
        public ?string $special,
    ) {
    }

    public static function of(CheckPushContent $push): self
    {
        return new self(
            $push->label,
            array_map(JournalPushDieResponse::of(...), $push->dice),
            $push->successes,
            $push->rolledBandKey,
            $push->rolledBandLabel,
            array_map(SheetChangeResponse::of(...), $push->changes),
            $push->special,
        );
    }
}
