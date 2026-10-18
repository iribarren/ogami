<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Upgrade;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\Tracker;

/**
 * Releases and campaigns the upgrade tests share: a "heist" GameSystem whose versions differ only
 * in what each test gives them.
 */
final class UpgradeFixtures
{
    /**
     * @param list<Field>     $fields
     * @param list<string>    $tags       Tag keys
     * @param list<Tracker>   $trackers
     * @param list<FactSlot>  $factSlots
     * @param list<Flow>      $flows
     * @param list<SceneType> $sceneTypes
     */
    public static function release(int $version, array $fields = [], array $tags = [], array $trackers = [], array $factSlots = [], array $flows = [], string $key = 'heist', array $sceneTypes = []): GameSystemSnapshot
    {
        return new GameSystemSnapshot(
            $key,
            'Heist',
            $version,
            null,
            [],
            [],
            trackers: $trackers,
            factSlots: $factSlots,
            sceneTypes: $sceneTypes,
            flows: $flows,
            tags: array_map(static fn (string $tag): Tag => new Tag($tag, ucfirst($tag)), $tags),
            sheet: new SheetTemplate($fields),
        );
    }

    /**
     * A version 1 release (schema version 1): no sheet, Trackers, Scene Types or Flows.
     */
    public static function bare(int $version = 1): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', $version, null, [], []);
    }

    public static function flow(string $key, bool $default = false): Flow
    {
        return new Flow($key, ucfirst($key), null, null, $default, FlowView::Journal, [], [], [new Phase('plan', 'Plan', null, PhaseMode::Once, SceneSelection::player(['legwork']))]);
    }

    /**
     * @param list<Tracker> $trackers the Trackers of the pinned release, started at their initial values
     */
    public static function campaign(GameSystemSnapshot $pinned, ?Flow $flow = null, array $trackers = []): Campaign
    {
        return Campaign::create(
            CampaignId::fromString('campaign-1'),
            'user-1',
            'The job',
            PinnedRelease::of($pinned->gameSystemKey(), $pinned->releaseVersion(), $pinned->name()),
            new \DateTimeImmutable('2026-10-12T09:00:00+00:00'),
            $trackers,
            $flow,
        );
    }
}
