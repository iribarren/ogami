<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use PHPUnit\Framework\Attributes\Test;

/**
 * The CampaignRepository contract, shared by the in-memory double and the Doctrine adapter so the
 * double cannot drift from the real thing. Ids are UUIDs because the database stores them as such.
 *
 * The using test class provides the repository, forgetLoaded(): what a new request would see (the
 * Doctrine test clears its entity managers, the in-memory one has nothing to forget), and
 * campaignsElsewhere(): the same campaigns as another request working at the same time sees them.
 */
trait CampaignRepositoryContract
{
    private const string OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5001';
    private const string OTHER_OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5002';
    private const string UNKNOWN_OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5003';

    abstract protected function campaigns(): CampaignRepository;

    abstract protected function forgetLoaded(): void;

    abstract protected function campaignsElsewhere(): CampaignRepository;

    #[Test]
    public function itKeepsEveryFieldOfACampaignWithItsSessionsAndScenes(): void
    {
        $campaign = Campaign::create(
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            self::OWNER,
            'The lost mine',
            PinnedRelease::of('free-journal', 3, 'Free journal'),
            new \DateTimeImmutable('2026-10-06T10:00:00+02:00'),
        );
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T10:05:00.123456+02:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T10:06:00+02:00'));
        $campaign->startScene('In the mine', new \DateTimeImmutable('2026-10-06T10:30:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07T18:00:00-05:00'));

        $this->campaigns()->add($campaign);
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertNotNull($loaded);
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $loaded->id()->toString());
        self::assertSame(self::OWNER, $loaded->ownerId());
        self::assertSame('The lost mine', $loaded->name());
        self::assertTrue($loaded->pinnedRelease()->equals(PinnedRelease::of('free-journal', 3, 'Free journal')));
        self::assertSame('Free journal', $loaded->pinnedRelease()->gameSystemName());
        self::assertSame(new \DateTimeImmutable('2026-10-06T08:00:00+00:00')->getTimestamp(), $loaded->createdAt()->getTimestamp());

        $sessions = $loaded->sessions();
        self::assertCount(2, $sessions);
        self::assertSame(1, $sessions[0]->number());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:05:00.123456+02:00'), $sessions[0]->startedAt());
        self::assertSame('+02:00', $sessions[0]->startedAt()->format('P'));
        self::assertSame([[1, 'At the gate'], [2, 'In the mine']], array_map(static fn ($scene): array => [$scene->number(), $scene->title()], $sessions[0]->scenes()));
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:30:00+00:00'), $sessions[0]->scenes()[1]->startedAt());
        self::assertSame(2, $sessions[1]->number());
        self::assertSame([], $sessions[1]->scenes());
        self::assertSame(2, $loaded->currentSession()?->number());
        self::assertNull($loaded->currentScene());
        self::assertEquals($campaign, $loaded);
    }

    #[Test]
    public function itKeepsTheKindSceneTypeAndHookOfEveryScene(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $sceneTypes = Snapshots::withSceneTypes('heist', 'Heist', 1);
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->startScene(null, new \DateTimeImmutable('2026-10-10T09:02:00+00:00'), $sceneTypes->sceneType('legwork'));
        $campaign->startScene('A quiet drink', new \DateTimeImmutable('2026-10-10T09:03:00+00:00'));
        $campaign->switchSceneType($sceneTypes->sceneType('firefight') ?? throw new \LogicException('No firefight.'));

        $this->campaigns()->add($campaign);
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId($id);

        self::assertNotNull($loaded);
        self::assertSame([
            [1, 'Session 1 begins', SceneKind::Hook, null, Hook::SessionOpening],
            [2, 'Legwork 1', SceneKind::Scene, 'legwork', null],
            [3, 'A quiet drink', SceneKind::Scene, 'firefight', null],
        ], array_map(
            static fn (Scene $scene): array => [$scene->number(), $scene->title(), $scene->kind(), $scene->sceneType(), $scene->hook()],
            $loaded->sessions()[0]->scenes(),
        ));
        self::assertEquals($campaign, $loaded);
    }

    #[Test]
    public function anUnknownOrMalformedIdFindsNoCampaign(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));

        self::assertNull($this->campaigns()->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058')));
        self::assertNull($this->campaigns()->ofId(CampaignId::fromString('not-a-uuid')));
    }

    #[Test]
    public function itRejectsADuplicateId(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        $this->expectException(CampaignAlreadyExists::class);
        $this->expectExceptionMessageIsOrContains('A campaign with id "01890a5d-ac96-774b-bcce-b302099a8057" already exists.');

        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OTHER_OWNER, '2026-10-07'));
    }

    #[Test]
    public function itListsOnlyTheOwnersCampaignsNewestFirst(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', self::OWNER, '2026-10-01'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', self::OWNER, '2026-10-03'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', self::OTHER_OWNER, '2026-10-02'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8004', self::OWNER, '2026-10-02'));
        $this->forgetLoaded();

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8004',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $this->idsOf($this->campaigns()->ownedBy(self::OWNER)));
        self::assertSame([], $this->campaigns()->ownedBy(self::UNKNOWN_OWNER));
        self::assertSame([], $this->campaigns()->ownedBy('not-a-uuid'));
    }

    #[Test]
    public function campaignsWithTheSameCreationTimeAreListedByIdDescending(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', self::OWNER, '2026-10-02 10:00:00'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', self::OWNER, '2026-10-02 10:00:00'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', self::OWNER, '2026-10-02 10:00:00'));
        $this->forgetLoaded();

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8002',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $this->idsOf($this->campaigns()->ownedBy(self::OWNER)));
    }

    /**
     * What every adapter guarantees about an unsaved change: nothing keeps it when nothing is
     * written. Another write may keep it in some adapters (see CampaignRepository).
     */
    #[Test]
    public function anUnsavedChangeIsNotKeptWhenNothingIsWritten(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->campaigns()->add($campaign);

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $loaded = $this->campaigns()->ofId($id);
        self::assertNotNull($loaded);
        $loaded->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->campaigns()->ownedBy(self::OWNER)[0]->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->forgetLoaded();

        self::assertNull($this->campaigns()->ofId($id)?->currentSession());
    }

    #[Test]
    public function savingKeepsTheChangedCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        $campaign = $this->campaigns()->ofId($id);
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $this->campaigns()->save($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 12:00:00'));
        $this->forgetLoaded();

        $saved = $this->campaigns()->ofId($id);
        self::assertSame(1, $saved?->currentSession()?->number());
        self::assertSame('Arrival', $saved->currentScene()?->title());

        // A second save on a later load keeps the scene added to the existing session too.
        $saved->startScene('Departure', new \DateTimeImmutable('2026-10-06 11:50:00'));
        $this->campaigns()->save($saved);
        $this->forgetLoaded();

        self::assertSame('Departure', $this->campaigns()->ofId($id)?->currentScene()?->title());
    }

    #[Test]
    public function itKeepsTheTrackerValuesAndTheirChanges(): void
    {
        $snapshot = Snapshots::withTrackers('heist', 'Heist', 1);
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-09T09:00:00+00:00'), $snapshot->trackers()));
        $this->forgetLoaded();

        $loaded = $this->campaigns()->ofId($id);
        self::assertNotNull($loaded);
        self::assertSame(['alarm' => 0, 'chaos' => 5, 'heat' => -5], $this->sorted($loaded->trackerValues()));

        $loaded->setTrackerValue($snapshot->tracker('heat') ?? throw new \LogicException('No heat tracker.'), 3);
        $this->campaigns()->save($loaded);
        $this->forgetLoaded();

        self::assertSame(['alarm' => 0, 'chaos' => 5, 'heat' => 3], $this->sorted($this->campaigns()->ofId($id)?->trackerValues() ?? []));
    }

    #[Test]
    public function aCampaignWithoutTrackersKeepsNone(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        self::assertSame([], $this->campaigns()->ofId($id)?->trackerValues());
    }

    #[Test]
    public function savingACampaignChangedAndSavedElsewhereSinceItWasLoadedFails(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();
        $mine = $this->campaigns()->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $theirs->startScene('Theirs', new \DateTimeImmutable('2026-10-06 11:01:00'));
        $this->campaignsElsewhere()->save($theirs);
        $mine->startSession(new \DateTimeImmutable('2026-10-06 11:02:00'));

        try {
            $this->campaigns()->save($mine);
            self::fail('The stale campaign was saved over the newer one.');
        } catch (CampaignModifiedConcurrently $exception) {
            self::assertSame('Campaign "01890a5d-ac96-774b-bcce-b302099a8057" was changed by another request. Reload it and try again.', $exception->getMessage());
        }

        $this->forgetLoaded();
        $kept = $this->campaignsElsewhere()->ofId($id);
        self::assertSame([1], array_map(static fn ($session): int => $session->number(), $kept?->sessions() ?? []));
        self::assertSame('Theirs', $kept?->currentScene()?->title());
    }

    #[Test]
    public function aCampaignCanBeSavedAgainAfterItsOwnSave(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->campaigns()->add($campaign);

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->campaigns()->save($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 12:00:00'));
        $this->campaigns()->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->campaigns()->ofId($id);
        self::assertSame(2, $loaded?->currentSession()?->number());

        // A later load is not stale either: it sees the newest save.
        $loaded->startSession(new \DateTimeImmutable('2026-10-06 13:00:00'));
        $this->campaigns()->save($loaded);
        $this->forgetLoaded();

        self::assertSame(3, $this->campaigns()->ofId($id)?->currentSession()?->number());
    }

    private function campaign(string $id, string $ownerId, string $createdAt): Campaign
    {
        return Campaign::create(
            CampaignId::fromString($id),
            $ownerId,
            'Campaign '.$id,
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
        );
    }

    /**
     * Stores need not keep the key order of tracker values (JSONB does not).
     *
     * @param array<string, int> $values
     *
     * @return array<string, int>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @param list<Campaign> $campaigns
     *
     * @return list<string>
     */
    private function idsOf(array $campaigns): array
    {
        return array_map(static fn (Campaign $campaign): string => $campaign->id()->toString(), $campaigns);
    }
}
