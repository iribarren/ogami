<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckPoolView;
use App\Play\Application\CheckView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Check of the campaign's pinned release that a Character can perform: it rolls dice or a pool,
 * exactly one of `dice` and `pool` is set. The Outcome bands are not exposed: the result of
 * performing the check names the band.
 */
#[OA\Schema(required: ['key', 'label', 'hint', 'dice', 'pool', 'difficulty'])]
final readonly class CheckResponse
{
    private function __construct(
        #[OA\Property(example: 'skill')]
        public string $key,
        #[OA\Property(description: 'What players see for the check.', example: 'Skill check')]
        public string $label,
        #[OA\Property(description: 'Shown where the check is chosen; null when the check has none.', example: 'A stat against a Difficulty Value.', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'The dice rolled, in dice notation; null for a pool check.', example: '1d10', nullable: true)]
        public ?string $dice,
        #[OA\Property(ref: new Model(type: CheckPoolResponse::class), description: 'The pool rolled, counted for successes; null for a check that rolls dice.', nullable: true)]
        public ?CheckPoolResponse $pool,
        #[OA\Property(ref: new Model(type: CheckDifficultyResponse::class), description: 'The difficulty the player sets when rolling; null when the check declares none.', nullable: true)]
        public ?CheckDifficultyResponse $difficulty,
    ) {
    }

    public static function fromView(CheckView $view): self
    {
        return new self(
            $view->key,
            $view->label,
            $view->hint,
            $view->dice,
            $view->pool instanceof CheckPoolView ? CheckPoolResponse::fromView($view->pool) : null,
            $view->difficulty instanceof \App\Play\Application\CheckDifficultyView ? CheckDifficultyResponse::fromView($view->difficulty) : null,
        );
    }
}
