<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\DiceExpression;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/rolls`. Documents the contract only: the controller
 * reads the expression from the request.
 */
#[OA\Schema(required: ['expression'])]
final readonly class RollRequest
{
    public function __construct(
        #[OA\Property(description: 'Dice notation: NdM, "d%", keep/drop selectors (kh, kl, dh, dl, k), integers, + - * / and parentheses. Case-insensitive; whitespace ignored.', example: '4d6kh3+2', maxLength: DiceExpression::MAX_LENGTH)]
        public string $expression,
    ) {
    }
}
