<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CastMemberView;
use App\Play\Application\SceneView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A scene of play, with an optional Scene Type of the campaign's pinned release, or a hook Scene
 * that records a phase boundary.
 */
#[OA\Schema(required: ['number', 'title', 'startedAt', 'kind', 'sceneType', 'sceneTypeName', 'hook', 'cast'])]
final readonly class SceneResponse
{
    /**
     * @param list<ItemRefResponse> $cast
     */
    private function __construct(
        #[OA\Property(description: 'Numbered from 1 within its session.', example: 1)]
        public int $number,
        #[OA\Property(example: 'At the gate')]
        public string $title,
        #[OA\Property(format: 'date-time')]
        public string $startedAt,
        #[OA\Property(description: 'A scene of play, or a hook Scene.', example: 'scene', enum: ['scene', 'hook'])]
        public string $kind,
        #[OA\Property(description: 'The key of the scene\'s Scene Type; null for a hook Scene or a scene without one.', example: 'legwork', nullable: true)]
        public ?string $sceneType,
        #[OA\Property(description: 'The name of the scene\'s Scene Type in the pinned release.', example: 'Legwork', nullable: true)]
        public ?string $sceneTypeName,
        #[OA\Property(description: 'The hook a hook Scene records; null for a scene of play.', example: null, nullable: true, enum: ['sessionOpening', 'sessionClosing', 'phaseOpening', 'phaseClosing', 'worldTurn', null])]
        public ?string $hook,
        #[OA\Property(
            description: 'The NPCs in the scene, in cast order, named as the campaign names them now; closed NPCs stay. Empty for a scene without a cast.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ItemRefResponse::class)),
        )]
        public array $cast,
    ) {
    }

    public static function fromView(SceneView $view): self
    {
        return new self($view->number, $view->title, $view->startedAt->format(\DATE_ATOM), $view->kind, $view->sceneType, $view->sceneTypeName, $view->hook, array_map(static fn (CastMemberView $member): ItemRefResponse => ItemRefResponse::of($member->id, $member->name), $view->cast));
    }
}
