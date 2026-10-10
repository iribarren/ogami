<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FlowSummaryView;
use OpenApi\Attributes as OA;

/**
 * A Flow of the campaign's pinned release: a guided way to play it.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'introduction', 'default', 'defaultView'])]
final readonly class FlowSummaryResponse
{
    private function __construct(
        #[OA\Property(example: 'heist')]
        public string $key,
        #[OA\Property(example: 'Heist')]
        public string $name,
        #[OA\Property(description: 'Null when the Flow has none.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'Shown when the campaign starts; null when the Flow has none.', nullable: true)]
        public ?string $introduction,
        #[OA\Property(description: 'Whether this is the release\'s default Flow (at most one is).')]
        public bool $default,
        #[OA\Property(description: 'How a campaign playing it opens.', example: 'focus', enum: ['focus', 'journal'])]
        public string $defaultView,
    ) {
    }

    public static function fromView(FlowSummaryView $view): self
    {
        return new self($view->key, $view->name, $view->description, $view->introduction, $view->default, $view->defaultView);
    }
}
