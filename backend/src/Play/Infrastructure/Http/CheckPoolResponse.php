<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckPoolView;
use OpenApi\Attributes as OA;

/**
 * The pool a check rolls instead of dice: dice counted for successes. Exactly one of `count` and
 * `countFormula` is set.
 */
#[OA\Schema(required: ['count', 'countFormula', 'countText', 'sides', 'successAt'])]
final readonly class CheckPoolResponse
{
    private function __construct(
        #[OA\Property(description: 'How many dice when the release gives a number; null when a formula gives it.', example: 4, nullable: true)]
        public ?int $count,
        #[OA\Property(description: 'The formula that gives the number of dice from the sheet; null when the release gives a number.', example: '{agility} + {stealth}', nullable: true)]
        public ?string $countFormula,
        #[OA\Property(description: 'The formula spelled in the labels players know; null when the release gives a number.', example: 'Agility + Stealth', nullable: true)]
        public ?string $countText,
        #[OA\Property(description: 'The faces of each die.', example: 6)]
        public int $sides,
        #[OA\Property(description: 'A die succeeds on this face or higher.', example: 6)]
        public int $successAt,
    ) {
    }

    public static function fromView(CheckPoolView $view): self
    {
        return new self($view->count, $view->countFormula, $view->countText, $view->sides, $view->successAt);
    }
}
