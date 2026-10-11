<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FlowSummaryView;
use OpenApi\Attributes as OA;

/**
 * A Flow of the campaign's pinned release: a guided way to play it.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'introduction', 'default', 'defaultView', 'oracles', 'trackers'])]
final readonly class FlowSummaryResponse
{
    /**
     * @param list<string> $oracles
     * @param list<string> $trackers
     */
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
        #[OA\Property(description: 'The keys of the oracles the Flow shows, in panel order.', type: 'array', items: new OA\Items(type: 'string'), example: ['fate', 'complications'])]
        public array $oracles,
        #[OA\Property(description: 'The keys of the Trackers the Flow shows while it guides the campaign, in panel order.', type: 'array', items: new OA\Items(type: 'string'), example: ['alarm', 'heat'])]
        public array $trackers,
    ) {
    }

    public static function fromView(FlowSummaryView $view): self
    {
        return new self($view->key, $view->name, $view->description, $view->introduction, $view->default, $view->defaultView, $view->oracles, $view->trackers);
    }
}
