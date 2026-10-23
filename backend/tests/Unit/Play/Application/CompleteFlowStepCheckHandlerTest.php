<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Formula\Domain\Formula;
use App\Play\Application\CampaignJournal;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Check\CheckDifficultyNotDeclared;
use App\Play\Domain\GameSystem\Check\CheckDifficultyOutOfRange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\SequentialJournalEntryIdGenerator;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Unit\Play\Domain\Campaign\FlowRun\CheckStepReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Completing a check step: "campaign-1" of "user-1" plays the release "checking"
 * (CheckStepReleases) at the step "sneak", with Ada (hands 4, so a skill modifier of 8) in the scene
 * and Bo in the party only.
 */
#[CoversClass(CompleteFlowStep::class)]
#[CoversClass(CompleteFlowStepHandler::class)]
final class CompleteFlowStepCheckHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryJournalEntryRepository $entries;
    private CampaignJournal $journal;
    private FixedClock $clock;

    #[Test]
    public function theCheckIsRolledForTheCharacterJournaledWithTheStepAndTheFlowRunBranches(): void
    {
        $this->store(CheckStepReleases::step(outcomes: ['fail' => new Outcome('fumble')]));

        // 1d10 shows 3: 3 + 8 = 11 is under the difficulty 13 - 1.
        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', 'sneak', null, null, null, null, null, 'ada'), 3);

        $entries = $this->entries->ofCampaign(CampaignId::fromString('campaign-1'));
        self::assertCount(1, $entries);
        self::assertSame(['check', 'ada', 'Ada', 'skill', 8, 13, 11, 'fail'], [
            $entries[0]->content()->kind(),
            $entries[0]->content()->toArray()['characterId'],
            $entries[0]->content()->toArray()['characterName'],
            $entries[0]->content()->toArray()['checkKey'],
            $entries[0]->content()->toArray()['modifier'],
            $entries[0]->content()->toArray()['difficulty'],
            $entries[0]->content()->toArray()['total'],
            $entries[0]->content()->toArray()['bandKey'],
        ]);
        self::assertSame(['key' => 'sneak', 'title' => 'Sneak past the guard', 'prompt' => null], $entries[0]->flowStep()?->toArray());
        $flowRun = $this->stored()->flowRun();
        self::assertSame([['sneak' => 'Fail'], 'fumble'], [$flowRun?->answers(), $flowRun?->stepKey()]);
    }

    #[Test]
    public function theEntryRecordsTheHintAndTheModifierBreakdownOfTheCheck(): void
    {
        $this->store(CheckStepReleases::step());

        // 1d10 shows 3: hands 4 * 2 = 8, so 11 at the default difficulty 13.
        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', 'sneak', null, null, null, null, null, 'ada'), 3);

        $content = $this->entries->ofCampaign(CampaignId::fromString('campaign-1'))[0]->content()->toArray();
        self::assertSame(['Sneak quietly.', '{hands} * 2', [['reference' => '{hands}', 'label' => 'Hands', 'value' => 4]]], [
            $content['hint'],
            $content['modifierFormula'],
            $content['modifierTerms'],
        ]);
    }

    #[Test]
    public function thePlayersDifficultyAndSituationalModifierApply(): void
    {
        $this->store(CheckStepReleases::step());

        // 6 + 8 + 2 = 16: at difficulty 20 that is a fail (up to 19), where the default 13 would be a success.
        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', 'sneak', null, null, null, null, null, 'ada', 20, 2), 6);

        $content = $this->entries->ofCampaign(CampaignId::fromString('campaign-1'))[0]->content()->toArray();
        self::assertSame([20, 2, 16, 'fail'], [$content['difficulty'], $content['situational'], $content['total'], $content['bandKey']]);
    }

    #[Test]
    public function aDifficultyTheStepFixesIsTheOneRolledAt(): void
    {
        $this->store(CheckStepReleases::step(difficulty: Formula::fromString('{tracker:heat} + 5')));

        $this->complete(new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', 'sneak', null, null, null, null, null, 'ada'), 6);

        self::assertSame(15, $this->entries->ofCampaign(CampaignId::fromString('campaign-1'))[0]->content()->toArray()['difficulty']);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aRefusedCheckRollsNothingJournalsNothingAndLeavesTheCampaignAsItWas(CheckStep $step, CompleteFlowStep $command, string $exception): void
    {
        $this->store($step);
        $before = clone $this->stored();

        try {
            // No number is scripted: a die rolled before the refusal would fail the test with a LogicException.
            $this->complete($command);
            self::fail('The step was completed.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertSame([], $this->entries->ofCampaign(CampaignId::fromString('campaign-1')));
        self::assertEquals($before, $this->stored());
    }

    /**
     * @return iterable<string, array{CheckStep, CompleteFlowStep, class-string<\Throwable>}>
     */
    public static function refusals(): iterable
    {
        $command = static fn (?string $character = 'ada', ?int $difficulty = null, ?int $situational = null, ?string $text = null): CompleteFlowStep => new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', 'sneak', $text, null, null, null, null, $character, $difficulty, $situational);

        yield 'no Character' => [CheckStepReleases::step(), $command(null), InvalidStepResult::class];
        yield 'an unknown Character' => [CheckStepReleases::step(), $command('ghost'), InvalidStepResult::class];
        yield 'a Character in the party only' => [CheckStepReleases::step(), $command('bo'), InvalidStepResult::class];
        yield 'a field of another kind' => [CheckStepReleases::step(), $command(text: 'sneak'), InvalidStepResult::class];
        yield 'a difficulty when the step fixes one' => [CheckStepReleases::step(difficulty: 15), $command(difficulty: 12), InvalidStepResult::class];
        yield 'a difficulty for a check without one' => [CheckStepReleases::step('flat'), $command(difficulty: 5), CheckDifficultyNotDeclared::class];
        yield 'a Tracker-based difficulty that resolves over the max' => [CheckStepReleases::step(difficulty: Formula::fromString('{tracker:heat} + 25')), $command(), CheckDifficultyOutOfRange::class];
        yield 'a Tracker-based difficulty that resolves under the min' => [CheckStepReleases::step(difficulty: Formula::fromString('{tracker:heat} - 5')), $command(), CheckDifficultyOutOfRange::class];
        yield 'a difficulty out of range' => [CheckStepReleases::step(), $command(difficulty: 30), CheckDifficultyOutOfRange::class];
        yield 'a situational modifier out of range' => [CheckStepReleases::step(), $command(situational: 101), SituationalModifierOutOfRange::class];
        yield 'a modifier that cannot be evaluated' => [CheckStepReleases::step('broken'), $command(), CheckEvaluationFailed::class];
    }

    private function complete(CompleteFlowStep $command, int ...$rolls): void
    {
        new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(...$rolls), $this->clock, new SequentialJournalEntryIdGenerator())($command);
    }

    private function stored(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('No campaign.');
    }

    private function store(CheckStep $step): void
    {
        $release = CheckStepReleases::release($step);
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->clock = new FixedClock('2026-10-10T09:30:00+00:00');
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add($release);
        $this->journal = new CampaignJournal(new OwnedCampaigns($this->campaigns), $this->entries, $releases, $this->clock);

        $at = new \DateTimeImmutable('2026-10-10T09:00:00+00:00');
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('checking', 1, 'Checking'), $at, [], $release->flow('play'));
        foreach ([['ada', 'Ada'], ['bo', 'Bo']] as [$id, $name]) {
            $campaign->addCharacter(CharacterId::fromString($id), $name, [], [], [], $release);
        }

        $campaign->startSession($at, $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada')]);
        $campaign->editCurrentSessionParty([CharacterId::fromString('bo')]);
        $this->campaigns->add($campaign);
    }
}
