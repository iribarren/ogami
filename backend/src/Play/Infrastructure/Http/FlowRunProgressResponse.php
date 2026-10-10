<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowRunProgress;
use OpenApi\Attributes as OA;

/**
 * Where a FlowRun stands: Act › Phase › Scene Type › part · step n/m. The Scene Type and part are
 * set only in a scene, the step numbers only on a step.
 */
#[OA\Schema(required: ['act', 'phase', 'sceneType', 'part', 'stepNumber', 'stepCount'])]
final readonly class FlowRunProgressResponse
{
    private function __construct(
        #[OA\Property(description: 'The Act the phase belongs to; null when the Flow has none.', example: 'Preparation', nullable: true)]
        public ?string $act,
        #[OA\Property(description: 'The name of the phase.', example: 'The job')]
        public string $phase,
        #[OA\Property(description: 'The name of the Scene Type being played; null at the scene pick.', example: 'Crew', nullable: true)]
        public ?string $sceneType,
        #[OA\Property(description: 'The part of the scene; null outside a scene.', example: 'setup', nullable: true, enum: ['sceneOpening', 'setup', 'play', 'open', 'closing', 'sceneClosing', null])]
        public ?string $part,
        #[OA\Property(description: 'The number of the step, from 1; null when no step waits. Condition steps are not counted.', example: 1, nullable: true)]
        public ?int $stepNumber,
        #[OA\Property(description: 'The number of steps in the part; null when no step waits.', example: 2, nullable: true)]
        public ?int $stepCount,
    ) {
    }

    public static function fromView(FlowRunProgress $progress): self
    {
        return new self($progress->act, $progress->phase, $progress->sceneType, $progress->part?->value, $progress->stepNumber, $progress->stepCount);
    }
}
