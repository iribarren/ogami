<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\CheckPushContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The pool part of a recorded check: the count and size of the pool, its dice, their groups and the successes as
 * first rolled, and the push once the roll was pushed.
 */
#[OA\Schema(required: ['count', 'countFormula', 'terms', 'size', 'sides', 'successAt', 'dice', 'successes', 'criticalsBonus', 'special', 'push'])]
final readonly class CheckPoolContentResponse
{
    /**
     * @param list<CheckModifierTermResponse> $terms
     * @param list<JournalPoolDieResponse>    $dice
     */
    private function __construct(
        #[OA\Property(description: 'The number of dice the release asked for, before the Situational modifier: its number, or what its formula gave.', example: 4)]
        public int $count,
        #[OA\Property(description: 'The formula that gave the count; null when the release gave a number.', example: '{agility} + {stealth}', nullable: true)]
        public ?string $countFormula,
        #[OA\Property(
            description: 'What each reference of the count formula read, in the order the formula first names them; empty without a formula.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CheckModifierTermResponse::class)),
        )]
        public array $terms,
        #[OA\Property(description: 'The dice rolled: the count plus the Situational modifier, kept between 1 and 100.', example: 5)]
        public int $size,
        #[OA\Property(description: 'The faces of each die.', example: 6)]
        public int $sides,
        #[OA\Property(description: 'A die succeeds on this face or higher.', example: 6)]
        public int $successAt,
        #[OA\Property(
            description: 'Every die rolled, in roll order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalPoolDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'How many dice succeeded, before the bonus of critical pairs; the total of the check is this plus the bonus of each critical pair.', example: 2)]
        public int $successes,
        #[OA\Property(description: 'The successes each critical pair (two dice showing the top face) adds to the total; 0 for a pool without critical pairs, and for entries recorded before them.', example: 2)]
        public int $criticalsBonus,
        #[OA\Property(description: 'The special result of the first roll; null for none, and for entries recorded before special results.', nullable: true, enum: ['critical', 'messyCritical', 'bestialFailure'])]
        public ?string $special,
        #[OA\Property(ref: new Model(type: CheckPushContentResponse::class), description: 'The push of the roll; null until the roll is pushed, and for entries recorded before pushes. Once pushed, the dice and successes above stay the first roll.', nullable: true)]
        public ?CheckPushContentResponse $push,
    ) {
    }

    public static function of(CheckPoolContent $pool): self
    {
        return new self(
            $pool->count,
            $pool->countFormula,
            array_map(CheckModifierTermResponse::of(...), $pool->terms),
            $pool->size,
            $pool->sides,
            $pool->successAt,
            array_map(JournalPoolDieResponse::of(...), $pool->dice),
            $pool->successes,
            $pool->criticalsBonus,
            $pool->special,
            $pool->push instanceof CheckPushContent ? CheckPushContentResponse::of($pool->push) : null,
        );
    }
}
