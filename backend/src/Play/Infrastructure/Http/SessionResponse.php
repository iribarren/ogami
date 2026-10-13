<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CastMemberView;
use App\Play\Application\SessionView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One sitting of play: under way until it ends.
 */
#[OA\Schema(required: ['number', 'startedAt', 'scenes', 'endedAt', 'party'])]
final readonly class SessionResponse
{
    /**
     * @param list<SceneResponse>   $scenes
     * @param list<ItemRefResponse> $party
     */
    private function __construct(
        #[OA\Property(description: 'Numbered from 1 within the campaign.', example: 1)]
        public int $number,
        #[OA\Property(format: 'date-time')]
        public string $startedAt,
        #[OA\Property(
            description: 'In number order; empty right after the session starts.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SceneResponse::class)),
        )]
        public array $scenes,
        #[OA\Property(description: 'When the session ended; null while it is under way.', format: 'date-time', nullable: true)]
        public ?string $endedAt,
        #[OA\Property(
            description: 'The Characters playing, in the order they joined, named as the campaign names them now. The next session starts with the same party.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ItemRefResponse::class)),
        )]
        public array $party,
    ) {
    }

    public static function fromView(SessionView $view): self
    {
        return new self(
            $view->number,
            $view->startedAt->format(\DATE_ATOM),
            array_map(SceneResponse::fromView(...), $view->scenes),
            $view->endedAt?->format(\DATE_ATOM),
            array_map(static fn (CastMemberView $member): ItemRefResponse => ItemRefResponse::of($member->id, $member->name), $view->party),
        );
    }
}
