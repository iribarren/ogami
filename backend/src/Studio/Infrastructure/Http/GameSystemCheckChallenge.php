<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The challenge dice a check's score is compared against (ADR 0049). Documents the contract only.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(
    description: 'The check rolls `count` challenge dice of `sides` sides, each compared on its own with the score; the total is the number of dice the score beats (strictly: ties go to the dice). With `dice` it is an action roll (score = dice + modifier + situational modifier); without, a progress roll (score = modifier + situational modifier, which is then required). The score is at most `scoreMax`. Refuses a pool and a difficulty.',
    required: ['count', 'sides'],
)]
final readonly class GameSystemCheckChallenge
{
    public function __construct(
        #[OA\Property(description: 'The number of challenge dice, 2 to 10.', example: 2)]
        public int $count,
        #[OA\Property(description: 'Faces of each die, 2 to 1,000.', example: 10)]
        public int $sides,
        #[OA\Property(description: 'The most the score can be, 1 to 1,000. Absent: no cap.', example: 10, nullable: true)]
        public ?int $scoreMax,
        #[OA\Property(description: 'The key of a `number` field of the sheet, which may be negative: a negative value equal to the action die cancels it. Only with `dice` that roll exactly one die, never on a progress roll. It makes `push` a burn: the score becomes the momentum.', example: 'momentum', nullable: true)]
        public ?string $momentum,
    ) {
    }
}
