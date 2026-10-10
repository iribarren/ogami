<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\GetJournal;
use App\Play\Application\GetJournalEntry;
use App\Play\Application\GetJournalEntryHandler;
use App\Play\Application\GetJournalHandler;
use App\Play\Application\JournalEntryNotFound;
use App\Play\Application\JournalEntryView;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordNoteHandler;
use App\Play\Application\RecordRoll;
use App\Play\Application\RecordRollHandler;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\ChoiceContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetJournal::class)]
#[CoversClass(GetJournalHandler::class)]
#[CoversClass(GetJournalEntry::class)]
#[CoversClass(GetJournalEntryHandler::class)]
#[CoversClass(JournalEntryView::class)]
#[CoversClass(JournalEntryNotFound::class)]
final class GetJournalHandlerTest extends JournalTestCase
{
    private GetJournalHandler $getJournal;
    private GetJournalEntryHandler $getEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getJournal = new GetJournalHandler($this->ownedCampaigns, $this->entries);
        $this->getEntry = new GetJournalEntryHandler($this->ownedCampaigns, $this->entries);
    }

    #[Test]
    public function aCampaignWithoutEntriesHasAnEmptyJournal(): void
    {
        self::assertSame([], ($this->getJournal)(new GetJournal('campaign-1', 'user-1')));
    }

    #[Test]
    public function theJournalListsEntriesInRecordingOrderWithTheirSessionAndScene(): void
    {
        $note = new RecordNoteHandler($this->journal);
        $roll = new RecordRollHandler($this->journal, new ScriptedRandomNumberGenerator(4));

        $this->clock->moveTo('2026-10-06T09:40:00+00:00');
        $note(new RecordNote('entry-b', 'campaign-1', 'user-1', 'Second, same time, larger id.'));
        $note(new RecordNote('entry-a', 'campaign-1', 'user-1', 'First at 09:40, smaller id.'));
        $this->clock->moveTo('2026-10-06T09:35:00+00:00');
        $roll(new RecordRoll('entry-c', 'campaign-1', 'user-1', '1d6'));
        // Another campaign's entries stay out.
        $this->startASceneIn('campaign-2');
        $note(new RecordNote('entry-0', 'campaign-2', 'user-1', 'Elsewhere.'));

        $journal = ($this->getJournal)(new GetJournal('campaign-1', 'user-1'));

        self::assertEquals([
            new JournalEntryView('entry-c', 1, 2, new \DateTimeImmutable('2026-10-06T09:35:00+00:00'), 'roll', [
                'kind' => 'roll',
                'expression' => '1d6',
                'total' => 4,
                'groups' => [['notation' => '1d6', 'sides' => 6, 'dice' => [['value' => 4, 'kept' => true]], 'subtotal' => 4]],
            ]),
            new JournalEntryView('entry-a', 1, 2, new \DateTimeImmutable('2026-10-06T09:40:00+00:00'), 'note', ['kind' => 'note', 'text' => 'First at 09:40, smaller id.']),
            new JournalEntryView('entry-b', 1, 2, new \DateTimeImmutable('2026-10-06T09:40:00+00:00'), 'note', ['kind' => 'note', 'text' => 'Second, same time, larger id.']),
        ], $journal);
    }

    #[Test]
    public function anotherPlayersJournalIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->getJournal)(new GetJournal('campaign-1', 'user-2'));
    }

    #[Test]
    public function oneEntryIsReadBackById(): void
    {
        (new RecordNoteHandler($this->journal))(new RecordNote('entry-1', 'campaign-1', 'user-1', 'The gate is shut.'));

        self::assertEquals(
            new JournalEntryView('entry-1', 1, 2, new \DateTimeImmutable(self::NOW), 'note', ['kind' => 'note', 'text' => 'The gate is shut.']),
            ($this->getEntry)(new GetJournalEntry('entry-1', 'campaign-1', 'user-1')),
        );
    }

    #[Test]
    public function anEntryRecordedByAFlowStepShowsTheStepAndItsChoice(): void
    {
        $this->entries->add(JournalEntry::reconstitute(
            JournalEntryId::fromString('entry-1'),
            CampaignId::fromString('campaign-1'),
            1,
            2,
            new \DateTimeImmutable(self::NOW),
            ChoiceContent::of('Did you gain an edge?', 'yes', 'Yes'),
            FlowStepSnapshot::of('gain-edge', 'Did you gain an edge?', null),
        ));

        self::assertEquals(
            new JournalEntryView('entry-1', 1, 2, new \DateTimeImmutable(self::NOW), 'choice', ['kind' => 'choice', 'question' => 'Did you gain an edge?', 'optionKey' => 'yes', 'label' => 'Yes'], ['key' => 'gain-edge', 'title' => 'Did you gain an edge?', 'prompt' => null]),
            ($this->getEntry)(new GetJournalEntry('entry-1', 'campaign-1', 'user-1')),
        );
    }

    #[Test]
    public function anUnknownEntryIsNotFound(): void
    {
        $this->expectException(JournalEntryNotFound::class);
        $this->expectExceptionMessageIsOrContains('Journal entry "entry-9" not found.');

        ($this->getEntry)(new GetJournalEntry('entry-9', 'campaign-1', 'user-1'));
    }

    #[Test]
    public function aBlankEntryIdIsNotFound(): void
    {
        $this->expectException(JournalEntryNotFound::class);

        ($this->getEntry)(new GetJournalEntry(' ', 'campaign-1', 'user-1'));
    }

    #[Test]
    public function anEntryOfAnotherCampaignIsNotFound(): void
    {
        $this->startASceneIn('campaign-2');
        (new RecordNoteHandler($this->journal))(new RecordNote('entry-1', 'campaign-2', 'user-1', 'Elsewhere.'));

        $this->expectException(JournalEntryNotFound::class);

        ($this->getEntry)(new GetJournalEntry('entry-1', 'campaign-1', 'user-1'));
    }

    #[Test]
    public function anEntryOfAnotherPlayersCampaignIsNotFound(): void
    {
        (new RecordNoteHandler($this->journal))(new RecordNote('entry-1', 'campaign-1', 'user-1', 'The gate is shut.'));

        $this->expectException(CampaignNotFound::class);

        ($this->getEntry)(new GetJournalEntry('entry-1', 'campaign-1', 'user-2'));
    }

    private function startASceneIn(string $campaignId): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString($campaignId));
        self::assertNotNull($campaign);
        $campaign->startScene('Elsewhere', new \DateTimeImmutable('2026-10-06T09:20:00+00:00'));
        $this->campaigns->save($campaign);
    }
}
