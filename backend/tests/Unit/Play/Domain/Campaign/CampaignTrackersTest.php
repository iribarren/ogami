<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\ChaosFactorBoundToTracker;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign holds a value for every Tracker of its pinned release; edits and changes clamp.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Tracker::class)]
#[CoversClass(UnknownCampaignTracker::class)]
#[CoversClass(ChaosFactorBoundToTracker::class)]
final class CampaignTrackersTest extends TestCase
{
    private GameSystemSnapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = Snapshots::withTrackers('heist', 'Heist', 1);
    }

    #[Test]
    public function aNewCampaignStartsEveryCounterAtItsInitialValueAndEveryClockAtZero(): void
    {
        self::assertSame(['alarm' => 0, 'heat' => -5, 'chaos' => 5], $this->campaign()->trackerValues());
    }

    #[Test]
    public function aCampaignOnAReleaseWithoutTrackersHasNone(): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'Free play', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable());

        self::assertSame([], $campaign->trackerValues());
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function handEdits(): iterable
    {
        yield 'counter in range' => ['heat', 3, 3];
        yield 'counter below min' => ['heat', -9, -5];
        yield 'counter above max' => ['heat', 12, 5];
        yield 'clock in range' => ['alarm', 4, 4];
        yield 'clock below zero' => ['alarm', -1, 0];
        yield 'clock past its segments' => ['alarm', 7, 6];
    }

    #[Test]
    #[DataProvider('handEdits')]
    public function aHandEditClampsToTheTrackerRange(string $key, int $value, int $expected): void
    {
        $campaign = $this->campaign();

        self::assertSame($expected, $campaign->setTrackerValue($this->tracker($key), $value));
        self::assertSame($expected, $campaign->trackerValue($key));
    }

    /**
     * @return iterable<string, array{string, TrackerOperation, int, int}>
     */
    public static function trackerChanges(): iterable
    {
        yield 'add to a counter' => ['heat', TrackerOperation::Add, 4, -1];
        yield 'add past a counter max' => ['heat', TrackerOperation::Add, 20, 5];
        yield 'subtract past a counter min' => ['chaos', TrackerOperation::Add, -10, 1];
        yield 'set a counter' => ['chaos', TrackerOperation::Set, 8, 8];
        yield 'set a counter above max' => ['chaos', TrackerOperation::Set, 99, 9];
        yield 'add to a clock' => ['alarm', TrackerOperation::Add, 2, 2];
        yield 'add past a clock end' => ['alarm', TrackerOperation::Add, 9, 6];
        yield 'set a clock below zero' => ['alarm', TrackerOperation::Set, -3, 0];
    }

    #[Test]
    #[DataProvider('trackerChanges')]
    public function aTrackerChangeAddsOrSetsAndClamps(string $key, TrackerOperation $operation, int $value, int $expected): void
    {
        $campaign = $this->campaign();

        self::assertSame($expected, $campaign->applyTrackerChange($this->tracker($key), $operation, $value));
        self::assertSame($expected, $campaign->trackerValue($key));
    }

    #[Test]
    public function changesAddUpOnTheCurrentValue(): void
    {
        $campaign = $this->campaign();
        $alarm = $this->tracker('alarm');

        $campaign->applyTrackerChange($alarm, TrackerOperation::Add, 2);
        $campaign->applyTrackerChange($alarm, TrackerOperation::Add, 3);

        self::assertSame(['alarm' => 5, 'heat' => -5, 'chaos' => 5], $campaign->trackerValues());
    }

    #[Test]
    public function aTrackerTheCampaignDoesNotHoldIsUnknown(): void
    {
        $campaign = $this->campaign();
        $stranger = Tracker::counter('luck', 'Luck', null, 0, 3, 0);

        foreach ([
            static fn (): int => $campaign->setTrackerValue($stranger, 1),
            static fn (): int => $campaign->applyTrackerChange($stranger, TrackerOperation::Add, 1),
            static fn (): int => $campaign->trackerValue('luck'),
        ] as $change) {
            try {
                $change();
                self::fail('An unknown tracker was accepted.');
            } catch (UnknownCampaignTracker $exception) {
                self::assertSame('Tracker "luck" not found.', $exception->getMessage());
            }
        }

        self::assertSame(['alarm' => 0, 'heat' => -5, 'chaos' => 5], $campaign->trackerValues());
    }

    #[Test]
    public function aBoundLikelihoodOracleTakesTheTrackerValueAsItsChaosFactor(): void
    {
        $campaign = $this->campaign();
        $campaign->setTrackerValue($this->tracker('chaos'), 7);

        self::assertSame(7, $campaign->chaosFactorFor($this->snapshot->likelihoodOracle('fate'), null));
    }

    #[Test]
    public function aBoundLikelihoodOracleRefusesAChaosFactor(): void
    {
        $this->expectException(ChaosFactorBoundToTracker::class);
        $this->expectExceptionMessageIsOrContains('Likelihood oracle "fate" takes its chaos factor from tracker "chaos": send no chaos factor.');

        $this->campaign()->chaosFactorFor($this->snapshot->likelihoodOracle('fate'), 5);
    }

    #[Test]
    public function anUnboundLikelihoodOracleTakesTheRequestedChaosFactor(): void
    {
        $campaign = $this->campaign();
        $omen = $this->snapshot->likelihoodOracle('omen');

        self::assertSame(2, $campaign->chaosFactorFor($omen, 2));
        self::assertNull($campaign->chaosFactorFor($omen, null));
    }

    #[Test]
    public function aCampaignIsReconstitutedWithItsTrackerValues(): void
    {
        $original = $this->campaign();
        $original->setTrackerValue($this->tracker('heat'), 2);

        $campaign = Campaign::reconstitute($original->id(), $original->ownerId(), $original->name(), $original->pinnedRelease(), $original->createdAt(), [], $original->trackerValues());

        self::assertEquals($original, $campaign);
        self::assertSame(2, $campaign->trackerValue('heat'));
    }

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function heatLevels(): iterable
    {
        yield 'lowest value' => [-5, 'Cold'];
        yield 'top of the first level' => [-1, 'Cold'];
        yield 'second level' => [0, 'Warm'];
        yield 'top of the second level' => [2, 'Warm'];
        yield 'the last level catches the rest' => [5, 'Hot'];
    }

    #[Test]
    #[DataProvider('heatLevels')]
    public function aCounterValueFallsInOneLevel(int $value, string $label): void
    {
        self::assertSame($label, $this->tracker('heat')->levelAt($value)?->label);
    }

    #[Test]
    public function aTrackerWithoutLevelsHasNoLevel(): void
    {
        self::assertNull($this->tracker('alarm')->levelAt(3));
        self::assertNull(Tracker::counter('edge', 'Edge', null, 0, 3, 0, [new TrackerLevel(0, 'No edge')])->levelAt(2));
    }

    private function campaign(): Campaign
    {
        return Campaign::create(
            CampaignId::fromString('campaign-1'),
            'user-1',
            'The job',
            PinnedRelease::of('heist', 1, 'Heist'),
            new \DateTimeImmutable('2026-10-09 09:00:00'),
            $this->snapshot->trackers(),
        );
    }

    private function tracker(string $key): Tracker
    {
        return $this->snapshot->tracker($key) ?? throw new \LogicException('No tracker '.$key);
    }
}
