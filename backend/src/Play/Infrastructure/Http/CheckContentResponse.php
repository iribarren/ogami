<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Check performed for a Character on the server: the dice, the modifier from the sheet, the
 * Situational modifier, the Check difficulty, the total and the Outcome band. A pool check has a
 * pool part instead of dice groups and a modifier, and its total is the successes plus the bonus of critical pairs.
 */
#[OA\Schema(required: ['kind', 'characterId', 'characterName', 'checkKey', 'checkLabel', 'expression', 'groups', 'diceTotal', 'modifier', 'situational', 'difficulty', 'total', 'bandKey', 'bandLabel', 'hint', 'modifierFormula', 'modifierTerms', 'pool', 'special'])]
final readonly class CheckContentResponse
{
    /**
     * @param list<JournalDiceGroupResponse>  $groups
     * @param list<CheckModifierTermResponse> $modifierTerms
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
        #[OA\Property(description: 'The hint of the check when it was performed; null when it had none, and for entries recorded before hints were kept.', example: 'Roll +Hot to seduce.', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'The text of the modifier formula; null for a check without one, and for entries recorded before the breakdown was kept.', example: '{hot} - 1', nullable: true)]
        public ?string $modifierFormula,
        #[OA\Property(
            description: 'What each reference of the modifier formula read, in the order the formula first names them; empty without a formula.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CheckModifierTermResponse::class)),
        )]
        public array $modifierTerms,
        #[OA\Property(ref: new Model(type: CheckPoolContentResponse::class), description: 'The pool rolled, with its dice and successes; null for a check that rolls dice, and for entries recorded before pools.', nullable: true)]
        public ?CheckPoolContentResponse $pool,
        #[OA\Property(description: 'The special result of the roll as it stands, after a push the push\'s: critical, messy critical or bestial failure. Null for none, for a check that rolls dice and for entries recorded before special results.', nullable: true, enum: ['critical', 'messyCritical', 'bestialFailure'])]
        public ?string $special,
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
            $content->hint(),
            $content->modifierFormula(),
            array_map(CheckModifierTermResponse::of(...), $content->modifierTerms()),
            $content->pool() instanceof CheckPoolContent ? CheckPoolContentResponse::of($content->pool()) : null,
            $content->special(),
        );
    }
}
