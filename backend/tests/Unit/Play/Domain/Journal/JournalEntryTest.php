<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\NoteContent;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JournalEntry::class)]
final class JournalEntryTest extends TestCase
{
    private const string ENTRY_ID = '01890a5d-ac96-774b-bcce-b302099a9001';

    #[Test]
    public function anEntryIsRecordedInTheCurrentSessionAndScene(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 10:30:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07 10:00:00'));
        $campaign->startScene('The gate', new \DateTimeImmutable('2026-10-07 10:05:00'));
        $campaign->startScene('The hall', new \DateTimeImmutable('2026-10-07 10:30:00'));
        $content = NoteContent::of('The hall is empty.');
        $recordedAt = new \DateTimeImmutable('2026-10-07 10:31:00');

        $entry = JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, $content, $recordedAt);

        self::assertSame(self::ENTRY_ID, $entry->id()->toString());
        self::assertTrue($campaign->id()->equals($entry->campaignId()));
        self::assertSame(2, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertSame($recordedAt, $entry->recordedAt());
        self::assertSame($content, $entry->content());
        self::assertNull($entry->flowStep());
    }

    #[Test]
    public function anEntryRecordedByAFlowStepKeepsItsSnapshot(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $step = FlowStepSnapshot::of('plan', 'The plan', 'How do you get in?');

        $entry = JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, NoteContent::of('Through the roof'), new \DateTimeImmutable('2026-10-06 10:06:00'), $step);

        self::assertSame($step, $entry->flowStep());
    }

    #[Test]
    public function nothingIsRecordedBeforeTheFirstSession(): void
    {
        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $this->campaign(), NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function nothingIsRecordedInASessionWithoutAScene(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 10:30:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07 10:00:00'));

        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function nothingIsRecordedOnceTheSessionHasEnded(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 12:00:00'));

        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function aStoredEntryIsRebuiltAsIs(): void
    {
        $content = NoteContent::of('Hello');
        $recordedAt = new \DateTimeImmutable('2026-10-06 10:00:00');

        $entry = JournalEntry::reconstitute(
            JournalEntryId::fromString(self::ENTRY_ID),
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            3,
            7,
            $recordedAt,
            $content,
            FlowStepSnapshot::of('plan', 'The plan', null),
        );

        self::assertSame(self::ENTRY_ID, $entry->id()->toString());
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $entry->campaignId()->toString());
        self::assertSame(3, $entry->sessionNumber());
        self::assertSame(7, $entry->sceneNumber());
        self::assertSame($recordedAt, $entry->recordedAt());
        self::assertSame($content, $entry->content());
        self::assertEquals(FlowStepSnapshot::of('plan', 'The plan', null), $entry->flowStep());
    }

    #[Test]
    public function anEntryWhoseChallengeWasBurnedCannotBePushedAgain(): void
    {
        $entry = $this->entryOf(CheckChallengeBurnContentTest::burnedEdge());

        $this->expectException(CheckAlreadyPushed::class);

        $entry->pushedWith(CheckChallengeBurnContentTest::burnedEdge());
    }

    #[Test]
    public function aChallengeEntryIsNotPushedWithContentThatIsNotBurned(): void
    {
        $entry = $this->entryOf(CheckChallengeBurnContentTest::edge());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('not the pushed content');

        $entry->pushedWith(CheckChallengeBurnContentTest::edge());
    }

    #[Test]
    public function aPlainDiceEntryCanNotBePushed(): void
    {
        $check = new Check('skill', 'Skill', null, DiceExpression::fromString('1d10'), null, null, [new CheckBand('all', 'All')]);
        $template = new SheetTemplate([]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(7), SheetValues::of($template, [], []), null, 0);
        $dice = CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels($template));
        $entry = $this->entryOf($dice);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('not a pool or challenge check');

        $entry->pushedWith(CheckChallengeBurnContentTest::burnedEdge());
    }

    private function entryOf(CheckContent $content): JournalEntry
    {
        return JournalEntry::reconstitute(
            JournalEntryId::fromString(self::ENTRY_ID),
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            1,
            1,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            $content,
        );
    }

    private function campaign(): Campaign
    {
        return Campaign::create(
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            'user-1',
            'The Long Road',
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable('2026-10-06 09:00:00'),
        );
    }
}
