<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a pick step picks from: the kind of item, how, how many, the Tags they must carry and the
 * candidates now. In mode "random" the server draws when the step is answered; in mode "choose"
 * the player sends the ids of between "min" and "max" candidates.
 */
#[OA\Schema(required: ['of', 'mode', 'min', 'max', 'tags', 'candidates'])]
final readonly class FlowPickStepResponse
{
    /**
     * @param list<PickTagResponse> $tags
     * @param list<ItemRefResponse> $candidates
     */
    private function __construct(
        #[OA\Property(description: 'The kind of item picked.', example: 'npc', enum: ['npc', 'thread'])]
        public string $of,
        #[OA\Property(description: 'Drawn by the server, or chosen by the player.', example: 'choose', enum: ['random', 'choose'])]
        public string $mode,
        #[OA\Property(description: 'The fewest items to pick; with fewer candidates the step completes with nothing picked.', example: 1)]
        public int $min,
        #[OA\Property(example: 2)]
        public int $max,
        #[OA\Property(
            description: 'The release Tags the items must carry, all of them; empty matches any open item.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: PickTagResponse::class)),
        )]
        public array $tags,
        #[OA\Property(
            description: 'The open items of the kind that carry all the Tags, in list order; empty when none match.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ItemRefResponse::class)),
        )]
        public array $candidates,
    ) {
    }

    public static function fromView(PickView $view): self
    {
        return new self(
            $view->of->value,
            $view->mode->value,
            $view->min,
            $view->max,
            array_map(PickTagResponse::of(...), $view->tags),
            array_map(static fn (PickCandidate $candidate): ItemRefResponse => ItemRefResponse::of($candidate->id, $candidate->name), $view->candidates),
        );
    }
}
