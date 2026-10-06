<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SessionView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['number', 'startedAt', 'scenes'])]
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
    ) {
    }

    public static function fromView(SessionView $view): self
    {
        return new self(
            $view->number,
            $view->startedAt->format(\DATE_ATOM),
            array_map(SceneResponse::fromView(...), $view->scenes),
        );
    }
}
