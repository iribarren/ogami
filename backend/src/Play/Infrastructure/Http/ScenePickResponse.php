<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SceneTypeSummaryView;
use App\Play\Domain\Campaign\FlowRun\ScenePickView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The scene pick a guided campaign waits on: a card per Scene Type offered, or the oracle table to
 * roll for it.
 */
#[OA\Schema(required: ['rule', 'cards', 'table', 'forced'])]
final readonly class ScenePickResponse
{
    /**
     * @param list<SceneTypeSummaryResponse> $cards
     */
    private function __construct(
        #[OA\Property(description: 'How the Scene Type is chosen: the Flow\'s sequence, the player, or an oracle table.', example: 'player', enum: ['sequence', 'player', 'oracle'])]
        public string $rule,
        #[OA\Property(
            description: 'The Scene Types offered, in offer order; none when the table is to be rolled.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SceneTypeSummaryResponse::class)),
        )]
        public array $cards,
        #[OA\Property(description: 'The key of the oracle table to roll for the Scene Type; null otherwise.', example: 'scene-kinds', nullable: true)]
        public ?string $table,
        #[OA\Property(description: 'Whether the only card is a Scene Type forced for the next scene.')]
        public bool $forced,
    ) {
    }

    public static function fromView(ScenePickView $view): self
    {
        return new self(
            $view->rule->value,
            array_map(static fn (\App\Play\Domain\GameSystem\SceneType $sceneType): SceneTypeSummaryResponse => SceneTypeSummaryResponse::fromView(SceneTypeSummaryView::of($sceneType)), $view->cards),
            $view->table,
            $view->forced,
        );
    }
}
