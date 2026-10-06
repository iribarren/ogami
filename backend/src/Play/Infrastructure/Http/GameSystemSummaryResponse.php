<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\GameSystemSummary;
use OpenApi\Attributes as OA;

/**
 * A GameSystem a campaign can be created with: its latest published release.
 */
#[OA\Schema(required: ['gameSystemKey', 'name', 'description', 'version'])]
final readonly class GameSystemSummaryResponse
{
    private function __construct(
        #[OA\Property(description: 'The key to create a campaign with.', example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 'Ironsworn')]
        public string $name,
        #[OA\Property(description: 'Null when the release has no description.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'The version a new campaign is pinned to.', example: 3)]
        public int $version,
    ) {
    }

    public static function fromView(GameSystemSummary $view): self
    {
        return new self($view->gameSystemKey, $view->name, $view->description, $view->version);
    }
}
