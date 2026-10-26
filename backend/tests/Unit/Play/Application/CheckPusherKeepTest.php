<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CheckPusher;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Unit\Play\Domain\Journal\CheckPoolContentTest;
use App\Tests\Unit\Play\Domain\Journal\CheckPushContentTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckPusher::class)]
final class CheckPusherKeepTest extends TestCase
{
    #[Test]
    public function aPushFromTheSheetLocksTheCampaignBeforeSavingIt(): void
    {
        $log = $this->log();

        $this->keep($log, fromTheSheet: true);

        self::assertSame(['lock campaign-1', 'save campaign-1', 'savePush'], $log->getArrayCopy());
    }

    #[Test]
    public function aFlowStepsPushTakesNoLockOfItsOwn(): void
    {
        $log = $this->log();

        $this->keep($log, fromTheSheet: false);

        self::assertSame(['save campaign-1', 'savePush'], $log->getArrayCopy());
    }

    /**
     * @return \ArrayObject<int, string>
     */
    private function log(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param \ArrayObject<int, string> $log
     */
    private function keep(\ArrayObject $log, bool $fromTheSheet): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
        $inner = new InMemoryJournalEntryRepository();
        $entry = JournalEntry::reconstitute(JournalEntryId::fromString('entry-1'), $campaign->id(), 1, 1, new \DateTimeImmutable('2026-10-12T10:00:00+00:00'), CheckPoolContentTest::sneak());
        $inner->add($entry);
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add($campaign);

        $entries = new class($inner, $log) implements JournalEntryRepository {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly JournalEntryRepository $inner, private \ArrayObject $log)
            {
            }

            public function add(JournalEntry $entry): void
            {
                $this->inner->add($entry);
            }

            public function savePush(JournalEntry $pushed, bool $asLatest = false): void
            {
                $this->log[] = 'savePush';
                $this->inner->savePush($pushed, $asLatest);
            }

            public function lockCampaignForPush(CampaignId $campaignId): void
            {
                $this->log[] = 'lock '.$campaignId->toString();
            }

            public function ofId(JournalEntryId $id): ?JournalEntry
            {
                return $this->inner->ofId($id);
            }

            public function ofCampaign(CampaignId $campaignId): array
            {
                return $this->inner->ofCampaign($campaignId);
            }

            public function latestOfCampaign(CampaignId $campaignId): ?JournalEntry
            {
                return $this->inner->latestOfCampaign($campaignId);
            }
        };
        $savingCampaigns = new class($campaigns, $log) implements CampaignRepository {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly CampaignRepository $inner, private \ArrayObject $log)
            {
            }

            public function add(Campaign $campaign): void
            {
                $this->inner->add($campaign);
            }

            public function save(Campaign $campaign): void
            {
                $this->log[] = 'save '.$campaign->id()->toString();
                $this->inner->save($campaign);
            }

            public function ofId(CampaignId $id): ?Campaign
            {
                return $this->inner->ofId($id);
            }

            public function ownedBy(string $ownerId): array
            {
                return $this->inner->ownedBy($ownerId);
            }
        };

        new CheckPusher($entries, $savingCampaigns, new ScriptedRandomNumberGenerator())->keep($campaign, $entry->pushedWith(CheckPushContentTest::pushedSneak()), $fromTheSheet);
    }
}
