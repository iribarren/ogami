<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;

/**
 * What a FlowRun command works with, handed over by its Campaign: the pinned release, the Flow
 * played, the command's time, and the campaign's sessions and scenes.
 */
final readonly class FlowRunContext
{
    public function __construct(
        private Campaign $campaign,
        public GameSystemSnapshot $release,
        public Flow $flow,
        public \DateTimeImmutable $at,
    ) {
    }

    /**
     * The number of the session under way; null when none is.
     */
    public function sessionNumber(): ?int
    {
        return $this->campaign->currentSession()?->number();
    }

    /**
     * The number of the current scene in the session under way; null when there is none.
     */
    public function sceneNumber(): ?int
    {
        return $this->campaign->currentScene()?->number();
    }

    /**
     * Starts a scene of play of this Scene Type with its default title.
     *
     * @return int its number in the session under way
     */
    public function startScene(SceneType $sceneType): int
    {
        return $this->campaign->startScene(null, $this->at, $sceneType)->number();
    }

    public function sceneType(string $key): SceneType
    {
        return $this->release->sceneType($key) ?? throw new \LogicException(\sprintf('The pinned release has no Scene Type "%s".', $key));
    }
}
