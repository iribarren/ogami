<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Check performed for a Character on the server: the dice, the modifier from the sheet, the
 * Situational modifier, the Check difficulty, the total and the Outcome band.
 */
#[OA\Schema(required: ['kind', 'characterId', 'characterName', 'checkKey', 'checkLabel', 'expression', 'groups', 'diceTotal', 'modifier', 'situational', 'difficulty', 'total', 'bandKey', 'bandLabel'])]
final readonly class CheckContentResponse
{
    /**
     * @param list<JournalDiceGroupResponse> $groups
     */
    private function __construct(
        #[OA\Property(enum: [CheckContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The Character the check was performed for.', format: 'uuid')]
        public string $characterId,
        #[OA\Property(description: 'The name of the Character when the check was performed.', example: 'Vi')]
        public string $characterName,
        #[OA\Property(example: 'skill')]
        public string $checkKey,
        #[OA\Property(description: 'The label of the check when it was performed.', example: 'Skill check')]
        public string $checkLabel,
        #[OA\Property(description: 'The normalized notation of the dice rolled.', example: '1d10')]
        public string $expression,
        #[OA\Property(
            description: 'Every dice group, in notation order; empty when the dice expression rolls no dice.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalDiceGroupResponse::class)),
        )]
        public array $groups,
        #[OA\Property(description: 'The value of the dice expression, counting kept dice only.', example: 7)]
        public int $diceTotal,
        #[OA\Property(description: 'What the check\'s modifier formula gave for the Character; 0 for a check without one.', example: 6)]
        public int $modifier,
        #[OA\Property(description: 'The Situational modifier.', example: 2)]
        public int $situational,
        #[OA\Property(description: 'The Check difficulty that applied; null when the check declares none.', example: 13, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The dice plus the modifier plus the Situational modifier.', example: 15)]
        public int $total,
        #[OA\Property(description: 'The key of the Outcome band the total falls in.', example: 'success')]
        public string $bandKey,
        #[OA\Property(description: 'The label of the Outcome band when the check was performed.', example: 'Success')]
        public string $bandLabel,
    ) {
    }

    public static function of(CheckContent $content): self
    {
        return new self(
            CheckContent::KIND,
            $content->characterId(),
            $content->characterName(),
            $content->checkKey(),
            $content->checkLabel(),
            $content->expression(),
            array_map(JournalDiceGroupResponse::of(...), $content->groups()),
            $content->diceTotal(),
            $content->modifier(),
            $content->situational(),
            $content->difficulty(),
            $content->total(),
            $content->bandKey(),
            $content->bandLabel(),
        );
    }
}
