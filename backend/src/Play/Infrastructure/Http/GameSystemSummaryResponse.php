<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\GameSystemSummary;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A GameSystem a campaign can be created with: its latest published release, with its Flows.
 */
#[OA\Schema(required: ['gameSystemKey', 'name', 'description', 'version', 'flows'])]
final readonly class GameSystemSummaryResponse
{
    /**
     * @param list<GameSystemFlowResponse> $flows
     */
    private function __construct(
        #[OA\Property(description: 'The key to create a campaign with.', example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 'Ironsworn')]
        public string $name,
        #[OA\Property(description: 'Null when the release has no description.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'The version a new campaign is pinned to.', example: 3)]
        public int $version,
        #[OA\Property(
            description: 'The Flows a new campaign can play, in definition order; empty for schema version 1 (a campaign is always played freely then).',
            type: 'array',
            items: new OA\Items(ref: new Model(type: GameSystemFlowResponse::class)),
        )]
        public array $flows,
    ) {
    }

    public static function fromView(GameSystemSummary $view): self
    {
        return new self($view->gameSystemKey, $view->name, $view->description, $view->version, array_map(GameSystemFlowResponse::fromView(...), $view->flows));
    }
}
