<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\EndSession;
use App\Play\Application\EndSessionHandler;
use App\Play\Application\FlowOutcomes;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PickSceneType;
use App\Play\Application\PickSceneTypeHandler;
use App\Play\Application\SwitchSceneType;
use App\Play\Application\SwitchSceneTypeHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\NoteContent;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\FlowNotesReleases;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\SequentialJournalEntryIdGenerator;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The flowOutcome entry a FlowRun command writes when the Flow decided something on its own, on
 * the release of FlowNotesReleases: "campaign-1" of "user-1" plays Flow "notes" in a session
 * started at 09:05, at the scene pick. The clock stands at 09:30.
 */
#[CoversClass(FlowOutcomes::class)]
#[CoversClass(CampaignJournal::class)]
#[CoversClass(CompleteFlowStepHandler::class)]
#[CoversClass(PickSceneTypeHandler::class)]
#[CoversClass(SwitchSceneTypeHandler::class)]
#[CoversClass(EndSessionHandler::class)]
final class FlowOutcomeEntryHandlerTest extends TestCase
{
    private GameSystemSnapshot $release;
    private InMemoryCampaignRepository $campaigns;
    private InMemoryJournalEntryRepository $entries;
    private InMemoryPublishedGameSystemReleases $releases;
    private FixedClock $clock;
    private CampaignJournal $journal;

    protected function setUp(): void
    {
        $this->release = FlowNotesReleases::snapshot();
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add($this->release);
        $this->clock = new FixedClock('2026-10-10T09:30:00+00:00');
        $this->journal = new CampaignJournal(new OwnedCampaigns($this->campaigns), $this->entries, $this->releases, $this->clock);
    }

    #[Test]
    public function aStepThatTookALabelledBandWritesAFlowOutcomeEntryRightAfterItsOwn(): void
    {
        $this->store('notes', 'gamble');

        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'gamble', null, null, null, null, null), 6);

        $entries = $this->journal();
        self::assertSame([['entry-000', 'roll'], ['entry-001', 'flowOutcome']], array_map(static fn (JournalEntry $entry): array => [$entry->id()->toString(), $entry->content()->kind()], $entries));
        self::assertEquals([$entries[0]->flowStep(), $entries[0]->sceneNumber(), $entries[0]->sessionNumber()], [$entries[1]->flowStep(), $entries[1]->sceneNumber(), $entries[1]->sessionNumber()]);
        self::assertSame(
            ['kind' => 'flowOutcome', 'notes' => [['kind' => 'band', 'label' => 'Jackpot'], ['kind' => 'tracker', 'key' => 'heat', 'name' => 'Heat', 'from' => 0, 'to' => 2]]],
            $entries[1]->content()->toArray(),
        );
    }

    #[Test]
    public function aStepThatDecidedNothingWritesOnlyItsOwnEntry(): void
    {
        $this->store('notes', 'gamble');

        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'gamble', null, null, null, null, null), 3);

        self::assertSame(['roll'], array_map(static fn (JournalEntry $entry): string => $entry->content()->kind(), $this->journal()));
    }

    #[Test]
    public function aTakenIdForTheFlowOutcomeEntryFailsLikeTheStepEntryAndSavesNothing(): void
    {
        $this->store('notes', 'gamble');
        // The generator hands out "entry-001" first: it is already in the journal.
        $this->entries->add($this->journal->entry('entry-001', $this->stored(), NoteContent::of('Taken'), $this->clock->now()));
        $before = $this->stored();

        try {
            $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'gamble', null, null, null, null, null), 6);
            self::fail('An entry id was taken twice.');
        } catch (JournalEntryAlreadyExists) {
            self::assertCount(1, $this->journal());
            self::assertEquals($before, $this->stored());
        }
    }

    #[Test]
    public function aFailingCommandWritesNeitherItsEntryNorAFlowOutcome(): void
    {
        $this->store('notes', 'broken');
        $before = $this->stored();

        try {
            $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'p', 'Go', null, null, null, null), 1);
            self::fail('The second Effect divides by zero.');
        } catch (FlowFormulaFailed) {
            self::assertSame([], $this->journal());
            self::assertEquals($before, $this->stored());
        }
    }

    #[Test]
    public function pickingASceneTypeWhoseSetupDecidesRecordsItsOutcomeInTheNewScene(): void
    {
        $this->store('notes');
        $campaign = $this->stored();
        $campaign->setTrackerValue($this->release->tracker('heat') ?? throw new \LogicException('No heat.'), 4);
        $this->campaigns->save($campaign);

        new PickSceneTypeHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(), $this->clock, $this->outcomes())(new PickSceneType('campaign-1', 'user-1', 'watch'));

        $entry = $this->journal()[0] ?? self::fail('No entry.');
        self::assertSame([1, 1, null], [$entry->sessionNumber(), $entry->sceneNumber(), $entry->flowStep()]);
        self::assertSame(
            ['kind' => 'flowOutcome', 'notes' => [['kind' => 'band', 'label' => 'Hot'], ['kind' => 'phaseEnd', 'phase' => 'Roaming']]],
            $entry->content()->toArray(),
        );
    }

    #[Test]
    public function aSceneTypeSwitchedByHandSettlesTheSetupInTheCurrentScene(): void
    {
        $this->store('notes', 'heist');

        new SwitchSceneTypeHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(), $this->clock, $this->outcomes())(new SwitchSceneType('campaign-1', 'user-1', 'watch'));

        // The player's own switch is no note; the condition step it lands on takes "Calm".
        self::assertSame([['kind' => 'flowOutcome', 'notes' => [['kind' => 'band', 'label' => 'Calm']]]], array_map(static fn (JournalEntry $entry): array => $entry->content()->toArray(), $this->journal()));
    }

    #[Test]
    public function notesWithNoSceneToRecordInAreDropped(): void
    {
        // Flow "closing" ends its session through a closing whose condition step takes a labelled band.
        $this->store('closing');

        new EndSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(), $this->clock, $this->outcomes())(new EndSession('campaign-1', 'user-1'));

        $campaign = $this->stored();
        self::assertSame([null, []], [$campaign->currentSession(), $this->journal()]);
        self::assertSame([], $campaign->takeFlowNotes(), 'The dropped notes are not left on the stored campaign.');
    }

    private function complete(CompleteFlowStep $command, int ...$rolls): void
    {
        new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(...$rolls), $this->clock, new SequentialJournalEntryIdGenerator())($command);
    }

    private function outcomes(): FlowOutcomes
    {
        return new FlowOutcomes($this->journal, new SequentialJournalEntryIdGenerator());
    }

    /**
     * @return list<JournalEntry>
     */
    private function journal(): array
    {
        return $this->entries->ofCampaign(CampaignId::fromString('campaign-1'));
    }

    private function stored(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('No campaign.');
    }

    /**
     * Stores "campaign-1" along the Flow with its session started, and in a scene of this Scene
     * Type when given (else at the scene pick).
     */
    private function store(string $flow, ?string $sceneType = null): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The notes', PinnedRelease::of('notes', 1, 'Notes'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), $this->release->trackers(), $this->release->flow($flow));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'), $this->release);
        if (null !== $sceneType) {
            $campaign->pickSceneType($sceneType, $this->release, new \DateTimeImmutable('2026-10-10T09:06:00+00:00'));
            $campaign->takeFlowNotes();
        }

        $this->campaigns->add($campaign);
    }
}
