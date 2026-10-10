<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FlowSummaryView;
use OpenApi\Attributes as OA;

/**
 * A Flow a new campaign can play, as the release catalog offers it.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'default'])]
final readonly class GameSystemFlowResponse
{
    private function __construct(
        #[OA\Property(description: 'The key to create a campaign with.', example: 'heist')]
        public string $key,
        #[OA\Property(example: 'Heist')]
        public string $name,
        #[OA\Property(description: 'Null when the Flow has none.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'Whether this is the release\'s default Flow (at most one is), to preselect.')]
        public bool $default,
    ) {
    }

    public static function fromView(FlowSummaryView $view): self
    {
        return new self($view->key, $view->name, $view->description, $view->default);
    }
}
