<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckPushView;
use OpenApi\Attributes as OA;

/**
 * The push of a pool check: what a player reads before pushing, and which choices to offer.
 */
#[OA\Schema(required: ['label', 'picks'])]
final readonly class CheckPushResponse
{
    /**
     * @param list<string> $picks
     */
    private function __construct(
        #[OA\Property(description: 'The cost of the push, as the release words it.', example: 'Take a physical Condition')]
        public string $label,
        #[OA\Property(
            description: 'The sheet field of each option the player picks when pushing (the cost marks an option of it), in cost order; empty when the cost needs no pick. A field with every option marked needs no pick.',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'physical'),
        )]
        public array $picks,
    ) {
    }

    public static function fromView(CheckPushView $view): self
    {
        return new self($view->label, $view->picks);
    }
}
