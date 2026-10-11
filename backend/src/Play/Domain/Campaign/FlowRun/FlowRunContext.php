<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerReference;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Tracker;

/**
 * What a FlowRun command works with, handed over by its Campaign: the pinned release, the Flow
 * played, the command's time, the campaign's sessions and scenes, and its Tracker values, which
 * Effects change.
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

    /**
     * The campaign's current value of a Tracker of the pinned release.
     */
    public function trackerValue(string $key): int
    {
        return $this->campaign->trackerValue($this->tracker($key));
    }

    /**
     * @return array<string, int> the campaign's current value of every Tracker of the pinned release, by key
     */
    public function trackerValues(): array
    {
        return $this->campaign->trackerValuesOf($this->release);
    }

    /**
     * Applies a tracker Effect: adds or sets a literal or another Tracker's current value, clamped
     * to the Tracker's range. It is no hand edit, so no FlowRun history records it.
     */
    public function applyTrackerEffect(TrackerEffect $effect): void
    {
        $value = $effect->value instanceof TrackerReference ? $this->trackerValue($effect->value->tracker) : $effect->value;
        $this->campaign->applyTrackerChange($this->tracker($effect->tracker), $effect->op, $value);
    }

    /**
     * Renames the current scene.
     *
     * @throws NoCurrentScene
     * @throws InvalidSceneTitle
     */
    public function renameScene(string $title): void
    {
        $this->campaign->renameCurrentScene($title);
    }

    /**
     * Switches the current scene's Scene Type in place, without telling the FlowRun (it switches
     * itself).
     *
     * @return ?string the scene's Scene Type key before the switch
     *
     * @throws NoCurrentScene
     */
    public function switchSceneType(SceneType $sceneType): ?string
    {
        $from = $this->campaign->currentScene()?->sceneType();
        $this->campaign->switchSceneType($sceneType);

        return $from;
    }

    private function tracker(string $key): Tracker
    {
        return $this->release->tracker($key) ?? throw new \LogicException(\sprintf('The pinned release has no Tracker "%s".', $key));
    }
}
