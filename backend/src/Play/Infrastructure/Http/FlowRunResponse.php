<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowRunProgress;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\ScenePickView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What the player sees of the FlowRun guiding a campaign: its status, where it stands, the step or
 * scene pick it waits on, whether "Move on" is offered and the next step named.
 */
#[OA\Schema(required: ['status', 'waitsForSession', 'progress', 'step', 'pick', 'canMoveOn', 'next'])]
final readonly class FlowRunResponse
{
    private function __construct(
        #[OA\Property(description: 'Active guides play; paused, the player plays freely and resumes later; completed after the last phase.', example: 'active', enum: ['active', 'paused', 'completed'])]
        public string $status,
        #[OA\Property(description: 'Whether no session is under way, so nothing can be played yet.')]
        public bool $waitsForSession,
        #[OA\Property(ref: new Model(type: FlowRunProgressResponse::class), description: 'Where the FlowRun stands; null once the Flow is complete.', nullable: true)]
        public ?FlowRunProgressResponse $progress,
        #[OA\Property(ref: new Model(type: FlowStepResponse::class), description: 'The step the FlowRun waits on; null at the scene pick and in open play.', nullable: true)]
        public ?FlowStepResponse $step,
        #[OA\Property(ref: new Model(type: ScenePickResponse::class), description: 'The scene pick the FlowRun waits on; null in a scene.', nullable: true)]
        public ?ScenePickResponse $pick,
        #[OA\Property(description: 'Whether "Move on" ends the phase now (a loop phase that is not ending yet, while guided).')]
        public bool $canMoveOn,
        #[OA\Property(description: 'The next step named, such as "Closing: What changed?" or "Flow complete".', example: 'Next scene: choose a scene type')]
        public string $next,
    ) {
    }

    public static function fromView(FlowRunView $view): self
    {
        return new self(
            $view->status->value,
            $view->waitsForSession,
            $view->progress instanceof FlowRunProgress ? FlowRunProgressResponse::fromView($view->progress) : null,
            $view->step instanceof FlowStepView ? FlowStepResponse::fromView($view->step) : null,
            $view->pick instanceof ScenePickView ? ScenePickResponse::fromView($view->pick) : null,
            $view->canMoveOn,
            $view->next,
        );
    }
}
