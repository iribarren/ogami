<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\SkipFlowStepHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\NoteContent;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\FlowOutcomesFor;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\SequentialJournalEntryIdGenerator;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Unit\Play\Domain\Campaign\FlowRun\FlowRunTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The handlers and the sheet Effects of a step (ADR 0027), on valid/v2-sheet-effects: "campaign-1"
 * of "user-1" plays the Flow "play" at the character step "you", which sets Sanity (track, max 99)
 * to Power. Ida (Power 60) and Bo (Power 40) are in the Setup scene. The clock stands at 09:30.
 */
#[CoversClass(CompleteFlowStepHandler::class)]
#[CoversClass(SkipFlowStepHandler::class)]
final class SheetChangeEntryHandlerTest extends FlowRunTestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryJournalEntryRepository $entries;
    private InMemoryPublishedGameSystemReleases $releases;
    private FixedClock $clock;
    private CampaignJournal $journal;

    #[Test]
    public function aCharacterStepThatChangedTheSheetWritesASheetChangeEntryRightAfterItsOwn(): void
    {
        $this->store(mandatory: true);

        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'you', null, null, null, null, null, 'ida'));

        $entries = $this->entries->ofCampaign(CampaignId::fromString('campaign-1'));
        self::assertSame([['entry-000', 'note'], ['entry-001', 'sheet-change']], array_map(static fn (JournalEntry $entry): array => [$entry->id()->toString(), $entry->content()->kind()], $entries));
        // The id of the second entry comes from the generator; both belong to the step's scene and step.
        self::assertEquals([$entries[0]->flowStep(), $entries[0]->sceneNumber()], [$entries[1]->flowStep(), $entries[1]->sceneNumber()]);
        self::assertSame(['kind' => 'sheet-change', 'characterId' => 'ida', 'characterName' => 'Ida', 'changes' => [['field' => 'sanity', 'before' => 99, 'after' => 60, 'dice' => null]]], $entries[1]->content()->toArray());
    }

    #[Test]
    public function aTakenIdForTheSheetChangeEntryFailsLikeTheStepEntryAndSavesNothing(): void
    {
        $this->store(mandatory: true);
        // The generator hands out "entry-001" first: it is already in the journal.
        $this->entries->add($this->journal->entry('entry-001', $this->stored(), NoteContent::of('Taken'), self::at()));
        $before = $this->stored();

        try {
            $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-000', 'you', null, null, null, null, null, 'ida'));
            self::fail('An entry id was taken twice.');
        } catch (JournalEntryAlreadyExists) {
            self::assertCount(1, $this->entries->ofCampaign(CampaignId::fromString('campaign-1')));
            self::assertEquals($before, $this->stored());
        }
    }

    #[Test]
    public function aSkippedCharacterStepWithSheetEffectsChangesNoSheetAndWritesNoEntry(): void
    {
        $this->store(mandatory: false);

        new SkipFlowStepHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, $this->clock, FlowOutcomesFor::of($this->campaigns, $this->entries, $this->releases, $this->clock))(new SkipFlowStep('campaign-1', 'user-1', 'you'));

        $stored = $this->stored();
        self::assertSame([['play', 'shock'], ['skip:you'], [99, 99], []], [
            self::position($stored),
            self::history($stored),
            array_map(static fn (string $id): mixed => $stored->character(CharacterId::fromString($id))?->values()['sanity']['current'] ?? null, ['ida', 'bo']),
            $this->entries->ofCampaign(CampaignId::fromString('campaign-1')),
        ]);
    }

    private function complete(CompleteFlowStep $command): void
    {
        new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(), $this->clock, new SequentialJournalEntryIdGenerator())($command);
    }

    private function stored(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('No campaign.');
    }

    private function store(bool $mandatory): void
    {
        $release = $this->sheetRelease($mandatory);
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add($release);
        $this->clock = new FixedClock('2026-10-10T09:30:00+00:00');
        $this->journal = new CampaignJournal(new OwnedCampaigns($this->campaigns), $this->entries, $this->releases, $this->clock);

        $campaign = self::guided($release, 'play');
        // The only Scene Type of the selection is picked at once.
        $campaign->startSession(self::at(), $release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60], $release);
        $campaign->addCharacter(CharacterId::fromString('bo'), 'Bo', [], [], ['pow' => 40], $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ida'), CharacterId::fromString('bo')]);
        $this->campaigns->add($campaign);
    }

    private function sheetRelease(bool $mandatory): GameSystemSnapshot
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseViews::fixtureContent('valid/v2-sheet-effects');
        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]],
        ]]]);

        /** @var array<string, mixed> $translated */
        $translated = ReleaseArrays::with($content, 'sceneTypes.0.setup.0.mandatory', $mandatory);

        return self::translate($translated);
    }
}
