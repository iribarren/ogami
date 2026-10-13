<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\PinnedReleaseView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The GameSystem release a campaign is pinned to (ADR 0014).
 */
#[OA\Schema(required: ['gameSystemKey', 'gameSystemName', 'version', 'theme', 'credits'])]
final readonly class PinnedReleaseResponse
{
    private function __construct(
        #[OA\Property(example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 'Ironsworn')]
        public string $gameSystemName,
        #[OA\Property(example: 3)]
        public int $version,
        #[OA\Property(description: 'The curated Theme the release asks for; null means the default app theme.', example: 'noir', nullable: true, enum: ['parchment', 'neon', 'noir', 'eldritch', 'starship'])]
        public ?string $theme,
        #[OA\Property(ref: new Model(type: CreditsResponse::class), description: 'The notice, license and link the release carries; null when it has none.', nullable: true)]
        public ?CreditsResponse $credits,
    ) {
    }

    public static function fromView(PinnedReleaseView $view): self
    {
        return new self($view->gameSystemKey, $view->gameSystemName, $view->version, $view->theme, CreditsResponse::fromCredits($view->credits));
    }
}
