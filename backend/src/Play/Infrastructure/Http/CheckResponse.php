<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CheckView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Check of the campaign's pinned release that a Character can perform. The Outcome bands are not
 * exposed: the result of performing the check names the band.
 */
#[OA\Schema(required: ['key', 'label', 'hint', 'dice', 'difficulty'])]
final readonly class CheckResponse
{
    private function __construct(
        #[OA\Property(example: 'skill')]
        public string $key,
        #[OA\Property(description: 'What players see for the check.', example: 'Skill check')]
        public string $label,
        #[OA\Property(description: 'Shown where the check is chosen; null when the check has none.', example: 'A stat against a Difficulty Value.', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'The dice rolled, in dice notation.', example: '1d10')]
        public string $dice,
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
            $view->difficulty instanceof \App\Play\Application\CheckDifficultyView ? CheckDifficultyResponse::fromView($view->difficulty) : null,
        );
    }
}
