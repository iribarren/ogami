<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SessionView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One sitting of play: under way until it ends.
 */
#[OA\Schema(required: ['number', 'startedAt', 'scenes', 'endedAt'])]
final readonly class SessionResponse
{
    /**
     * @param list<SceneResponse> $scenes
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
    ) {
    }

    public static function fromView(SessionView $view): self
    {
        return new self(
            $view->number,
            $view->startedAt->format(\DATE_ATOM),
            array_map(SceneResponse::fromView(...), $view->scenes),
            $view->endedAt?->format(\DATE_ATOM),
        );
    }
}
