<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\EndFlowScene;
use App\Play\Application\EndFlowSceneHandler;
use App\Play\Application\FlowOutcomes;
use App\Play\Application\MoveOn;
use App\Play\Application\MoveOnHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PickSceneTypeByOracle;
use App\Play\Application\PickSceneTypeByOracleHandler;
use App\Play\Application\ResumeGuidance;
use App\Play\Application\ResumeGuidanceHandler;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\SkipFlowStepHandler;
use App\Play\Application\StartSession;
use App\Play\Application\StartSessionHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\FlowNotesReleases;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\SequentialJournalEntryIdGenerator;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every FlowRun handler that can note a Flow decision journals it: one flowOutcome entry written
 * by the command, in the scene it left current. The release extends FlowNotesReleases with
 * scene types and Flows whose condition steps take the labelled band "Calm", "Quiet", "Aftermath",
 * "Winding down" at the first step they reach; "campaign-1" of "user-1" plays one of the Flows in
 * a session started at 09:05. The clock stands at 09:30.
 */
#[CoversClass(FlowOutcomes::class)]
#[CoversClass(SkipFlowStepHandler::class)]
#[CoversClass(MoveOnHandler::class)]
#[CoversClass(EndFlowSceneHandler::class)]
#[CoversClass(ResumeGuidanceHandler::class)]
#[CoversClass(StartSessionHandler::class)]
#[CoversClass(PickSceneTypeByOracleHandler::class)]
final class FlowOutcomeEveryHandlerTest extends TestCase
{
    private GameSystemSnapshot $release;
    private InMemoryCampaignRepository $campaigns;
    private InMemoryJournalEntryRepository $entries;
    private InMemoryPublishedGameSystemReleases $releases;
    private FixedClock $clock;
    private OwnedCampaigns $owned;
    private FlowOutcomes $outcomes;

    protected function setUp(): void
    {
        $this->release = $this->snapshot();
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add($this->release);
        $this->clock = new FixedClock('2026-10-10T09:30:00+00:00');
        $this->owned = new OwnedCampaigns($this->campaigns);
        $this->outcomes = new FlowOutcomes(new CampaignJournal($this->owned, $this->entries, $this->releases, $this->clock), new SequentialJournalEntryIdGenerator());
    }

    #[Test]
    public function skippingAStepWritesTheOutcomeOfTheStepItLandsOn(): void
    {
        $this->store('skipping');
        $campaign = $this->campaign();
        $campaign->pickSceneType('hush', $this->release, new \DateTimeImmutable('2026-10-10T09:06:00+00:00'));
        $this->campaigns->save($campaign);

        new SkipFlowStepHandler($this->owned, $this->campaigns, $this->releases, $this->clock, $this->outcomes)(new SkipFlowStep('campaign-1', 'user-1', 'maybe'));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Quiet']], 1);
    }

    #[Test]
    public function endingASceneWritesTheOutcomeOfItsClosing(): void
    {
        $this->store('skipping');
        $campaign = $this->campaign();
        $campaign->pickSceneType('wrapup', $this->release, new \DateTimeImmutable('2026-10-10T09:06:00+00:00'));
        $this->campaigns->save($campaign);

        new EndFlowSceneHandler($this->owned, $this->campaigns, $this->releases, $this->clock, $this->outcomes)(new EndFlowScene('campaign-1', 'user-1', 1));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Aftermath']], 1);
    }

    #[Test]
    public function movingOnAtTheScenePickWritesTheOutcomeOfThePhaseClosing(): void
    {
        $this->store('moving');

        new MoveOnHandler($this->owned, $this->campaigns, $this->releases, $this->clock, $this->outcomes)(new MoveOn('campaign-1', 'user-1', 'roam'));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Winding down']], 1);
    }

    #[Test]
    public function startingASessionWritesTheOutcomeOfTheSceneItAutoPicks(): void
    {
        $this->store('single', startSession: false);

        new StartSessionHandler($this->owned, $this->campaigns, $this->releases, $this->clock, $this->outcomes)(new StartSession('campaign-1', 'user-1'));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Calm']], 1);
    }

    #[Test]
    public function resumingGuidanceWritesTheOutcomeOfTheSceneItAutoPicks(): void
    {
        $this->store('single', startSession: false);
        $campaign = $this->campaign();
        $campaign->pauseGuidance(new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'), $this->release);
        $campaign->takeFlowNotes();
        $this->campaigns->save($campaign);
        self::assertSame([], $this->journal());

        new ResumeGuidanceHandler($this->owned, $this->campaigns, $this->releases, $this->clock, $this->outcomes)(new ResumeGuidance('campaign-1', 'user-1'));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Calm']], 1);
    }

    #[Test]
    public function pickingTheSceneTypeByOracleWritesTheOutcomeOfItsSetup(): void
    {
        $this->store('oracle');

        new PickSceneTypeByOracleHandler($this->owned, $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(3), $this->clock, $this->outcomes)(new PickSceneTypeByOracle('campaign-1', 'user-1'));

        $this->assertOutcome([['kind' => 'band', 'label' => 'Calm']], 1);
    }

    /**
     * The journal holds the one flowOutcome entry with these notes, in that scene of session 1.
     *
     * @param list<array<string, mixed>> $notes
     */
    private function assertOutcome(array $notes, int $scene): void
    {
        $entries = $this->journal();
        self::assertCount(1, $entries);
        self::assertSame(['kind' => 'flowOutcome', 'notes' => $notes], $entries[0]->content()->toArray());
        self::assertSame([1, $scene, null], [$entries[0]->sessionNumber(), $entries[0]->sceneNumber(), $entries[0]->flowStep()]);
    }

    /**
     * @return list<JournalEntry>
     */
    private function journal(): array
    {
        return $this->entries->ofCampaign(CampaignId::fromString('campaign-1'));
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('No campaign.');
    }

    /**
     * Stores "campaign-1" along the Flow, with its session started unless told otherwise (the
     * notes of that start are not journaled).
     */
    private function store(string $flow, bool $startSession = true): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The notes', PinnedRelease::of('notes', 1, 'Notes'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), $this->release->trackers(), $this->release->flow($flow));
        if ($startSession) {
            $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'), $this->release);
            $campaign->takeFlowNotes();
        }

        $this->campaigns->add($campaign);
    }

    private function snapshot(): GameSystemSnapshot
    {
        /** @var array{oracles: array<string, mixed>, sceneTypes: list<array<string, mixed>>, flows: list<array<string, mixed>>} $content */
        $content = FlowNotesReleases::content();
        $band = static fn (string $label): array => ['kind' => 'condition', 'title' => 'How is it?', 'tracker' => 'heat', 'bands' => [['label' => $label]]];
        $sceneType = static fn (string $key, array $setup, array $closing = []): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => $closing];
        $flow = static fn (string $key, array $selection, array $phase = []): array => ['key' => $key, 'name' => ucfirst($key), 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat'], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => $selection] + $phase,
        ]];
        $player = ['rule' => 'player', 'sceneTypes' => ['hush', 'wrapup', 'watch']];

        $content['oracles']['tables'] = [['key' => 'scene-kinds', 'name' => 'Scene kinds', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'A watch', 'sceneType' => 'watch']]]];
        $content['sceneTypes'][] = $sceneType('hush', [['key' => 'maybe', 'kind' => 'prompt', 'title' => 'Anything else?'], ['key' => 'quiet', 'title' => 'How is it?'] + $band('Quiet')]);
        $content['sceneTypes'][] = $sceneType('wrapup', [], [['key' => 'after', 'title' => 'How is it?'] + $band('Aftermath')]);
        $content['flows'][] = $flow('skipping', $player);
        $content['flows'][] = $flow('moving', $player, ['phaseClosing' => [['key' => 'winding', 'title' => 'How is it?'] + $band('Winding down'), ['key' => 'last', 'kind' => 'prompt', 'title' => 'Last words?']]]);
        $content['flows'][] = $flow('single', ['rule' => 'player', 'sceneTypes' => ['watch']]);
        $content['flows'][] = $flow('oracle', ['rule' => 'oracle', 'table' => 'scene-kinds']);

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }
}
