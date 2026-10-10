<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CompleteFlowStep::class)]
#[CoversClass(CompleteFlowStepHandler::class)]
final class CompleteFlowStepHandlerTest extends FlowRunHandlerTestCase
{
    #[Test]
    public function aPromptIsRecordedAsANoteWithItsStepAndTheFlowRunAdvances(): void
    {
        $this->complete(self::command('intro', text: '  Ada, a fixer '));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame(['entry-1', 1, 1], [$entry->id()->toString(), $entry->sessionNumber(), $entry->sceneNumber()]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertSame(['kind' => 'note', 'text' => 'Ada, a fixer'], $entry->content()->toArray());
        self::assertSame(['key' => 'intro', 'title' => 'Who walks in?', 'prompt' => 'Describe the first person.'], $entry->flowStep()?->toArray());
        $flowRun = $this->stored('campaign-1')->flowRun();
        self::assertSame([['intro' => 'Ada, a fixer'], 'dice'], [$flowRun?->answers(), $flowRun?->stepKey()]);
    }

    #[Test]
    public function aRollIsRolledByTheServerFromTheStepsDice(): void
    {
        $this->atStep('dice');

        $this->complete(self::command('dice'), 4);

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame(['roll', '1d6', 4], [$entry->content()->kind(), $entry->content()->toArray()['expression'], $entry->content()->toArray()['total']]);
        self::assertSame('dice', $entry->flowStep()?->key);
        self::assertSame([['dice' => '4'], 'omen'], [$this->stored('campaign-1')->flowRun()?->answers(), $this->stored('campaign-1')->flowRun()?->stepKey()]);
    }

    #[Test]
    public function aTableIsRolledOnTheStepsOracleTable(): void
    {
        $this->atStep('omen');

        $this->complete(self::command('omen'), 5);

        $content = $this->onlyEntryOf('campaign-1')->content()->toArray();
        self::assertSame(['oracle-table', 'omens', 'Omens'], [$content['kind'], $content['oracleKey'], $content['oracleName']]);
        self::assertSame([['tableKey' => 'omens', 'tableName' => 'Omens', 'dice' => '1d6', 'total' => 5, 'text' => 'Storm', 'nestedTableKey' => null]], $content['steps']);
        self::assertSame(['omen' => 'Storm'], $this->stored('campaign-1')->flowRun()?->answers());
    }

    #[Test]
    public function anOracleStepWithoutALikelihoodTakesThePlayersAndAChaosFactor(): void
    {
        $this->atStep('ask');

        // "Unlikely" targets 35; chaos 7 is 2 above neutral, at 5 per point: 45. A roll of 40 is a yes.
        $this->complete(self::command('ask', likelihood: 'unlikely', chaosFactor: 7), 40);

        $content = $this->onlyEntryOf('campaign-1')->content()->toArray();
        self::assertSame(['likelihood', 'fate', 'Is the gate open?', 'yes', 45, 'unlikely', 7], [$content['kind'], $content['oracleKey'], $content['question'], $content['answer'], $content['effectiveTarget'], $content['likelihood'], $content['chaosFactor']]);
        self::assertSame('Yes', $this->stored('campaign-1')->flowRun()?->answers()['ask']);
    }

    #[Test]
    public function anOracleStepThatFixesTheLikelihoodAsksAtIt(): void
    {
        $this->atStep('ask-even');

        $this->complete(self::command('ask-even'), 60);

        $content = $this->onlyEntryOf('campaign-1')->content()->toArray();
        self::assertSame(['no', 'even', 5, 'Is it guarded?'], [$content['answer'], $content['likelihood'], $content['chaosFactor'], $content['question']]);
    }

    #[Test]
    public function aChoiceRecordsTheQuestionAndTheOptionChosen(): void
    {
        $this->atStep('fork');

        $this->complete(self::command('fork', optionKey: 'right'));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame(['kind' => 'choice', 'question' => 'Which way?', 'optionKey' => 'right', 'label' => 'Go right'], $entry->content()->toArray());
        self::assertSame(['fork' => 'Go right'], $this->stored('campaign-1')->flowRun()?->answers());
        $flowRun = $this->stored('campaign-1')->flowRun();
        self::assertSame([FlowRunStage::Scene, ScenePart::Open], [$flowRun->stage(), $flowRun->part()]);
    }

    /**
     * @return iterable<string, array{string, CompleteFlowStep, class-string<\Throwable>, string}>
     */
    public static function refusedResults(): iterable
    {
        yield 'a prompt with an option' => ['intro', self::command('intro', text: 'Ada', optionKey: 'left'), InvalidStepResult::class, 'Step "intro" is a prompt step: it takes no "optionKey".'];
        yield 'a prompt without text' => ['intro', self::command('intro'), InvalidStepResult::class, 'Step "intro" is a prompt step: it needs a "text".'];
        yield 'a prompt with blank text' => ['intro', self::command('intro', text: '  '), InvalidStepResult::class, 'Step "intro" needs an answer.'];
        yield 'a roll with text' => ['dice', self::command('dice', text: 'a six'), InvalidStepResult::class, 'Step "dice" is a roll step: it takes no "text".'];
        yield 'a table with a likelihood' => ['omen', self::command('omen', likelihood: 'even'), InvalidStepResult::class, 'Step "omen" is a table step: it takes no "likelihood".'];
        yield 'an oracle with text' => ['ask', self::command('ask', text: 'yes', likelihood: 'even'), InvalidStepResult::class, 'Step "ask" is a oracle step: it takes no "text".'];
        yield 'an oracle without a likelihood' => ['ask', self::command('ask'), InvalidStepResult::class, 'Step "ask" is a oracle step: it needs a "likelihood".'];
        yield 'an oracle with an unknown likelihood' => ['ask', self::command('ask', likelihood: 'certain'), InvalidLikelihoodOracle::class, ''];
        yield 'an oracle with a chaos factor out of range' => ['ask', self::command('ask', likelihood: 'even', chaosFactor: 10), InvalidLikelihoodOracle::class, ''];
        yield 'a likelihood for an oracle that fixes one' => ['ask-even', self::command('ask-even', likelihood: 'even'), InvalidStepResult::class, 'Step "ask-even" asks at likelihood "even": it takes no other.'];
        yield 'a choice without an option' => ['fork', self::command('fork'), InvalidStepResult::class, 'Step "fork" is a choice step: it needs a "optionKey".'];
        yield 'a choice with an unknown option' => ['fork', self::command('fork', optionKey: 'up'), InvalidStepResult::class, 'Choice step "fork" has no option "up".'];
        yield 'a choice with a chaos factor' => ['fork', self::command('fork', optionKey: 'left', chaosFactor: 5), InvalidStepResult::class, 'Step "fork" is a choice step: it takes no "chaosFactor".'];
    }

    /**
     * @param class-string<\Throwable> $error
     */
    #[Test]
    #[DataProvider('refusedResults')]
    public function aRefusedResultRecordsNothingAndLeavesTheCampaignAsItWas(string $atStep, CompleteFlowStep $command, string $error, string $message): void
    {
        $this->atStep($atStep);
        $before = $this->stored('campaign-1');

        try {
            $this->complete($command, 40);
            self::fail('A refused result completed the step.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($error, $exception);
            if ('' !== $message) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        self::assertSame([], $this->journalOf('campaign-1'));
        self::assertEquals($before, $this->stored('campaign-1'));
    }

    #[Test]
    public function aStepTheFlowRunDoesNotWaitOnIsRefused(): void
    {
        $this->expectException(FlowRunPositionMismatch::class);
        $this->expectExceptionMessageIsOrContains('The FlowRun is at step "intro", not at step "dice".');

        $this->complete(self::command('dice'), 4);
    }

    #[Test]
    public function aPausedGuidanceIsRefusedAndAFreeCampaignHasNoSteps(): void
    {
        $paused = $this->stored('campaign-1');
        $paused->pauseGuidance(self::at('09:20'));
        $this->campaigns->save($paused);

        foreach (['campaign-1', 'campaign-2'] as $id) {
            try {
                $this->complete(self::command('intro', text: 'Ada', campaignId: $id));
                self::fail('A step was completed without guidance.');
            } catch (FlowRunNotActive) {
                self::assertSame([], $this->journalOf($id));
            }
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->complete(self::command('intro', text: 'Ada', userId: 'user-2'));
    }

    #[Test]
    public function anEntryIdAlreadyTakenRecordsNothingMoreAndDoesNotCompleteTheStep(): void
    {
        $this->complete(self::command('intro', text: 'Ada'));
        $before = $this->stored('campaign-1');

        try {
            $this->complete(self::command('dice'), 4);
            self::fail('An entry id was taken twice.');
        } catch (JournalEntryAlreadyExists) {
            self::assertCount(1, $this->journalOf('campaign-1'));
            self::assertEquals($before, $this->stored('campaign-1'));
        }
    }

    #[Test]
    public function theEntryOfTheLastStepOfASceneBelongsToThatSceneEvenThoughTheNextOneStarts(): void
    {
        $this->campaigns->add($this->guided('campaign-3', 'chain'));
        $campaign = $this->stored('campaign-3');
        $campaign->endFlowScene(1, $this->release, self::at('09:10'));
        $this->campaigns->save($campaign);

        $this->complete(self::command('wrap', text: 'The crew split up', campaignId: 'campaign-3'));

        $entry = $this->onlyEntryOf('campaign-3');
        self::assertSame([1, 1], [$entry->sessionNumber(), $entry->sceneNumber()]);
        self::assertSame([2, 'Solo 2'], [$this->stored('campaign-3')->currentScene()?->number(), $this->stored('campaign-3')->currentScene()?->title()]);
    }

    #[Test]
    public function aStepThatStartsASceneTheSessionCannotHoldRecordsNothing(): void
    {
        // The 200th scene of the session is closing; the next one does not fit.
        $scenes = array_map(static fn (int $number): Scene => Scene::reconstitute($number, 'Solo '.$number, self::at('09:05'), sceneType: 'solo'), range(1, Session::MAX_SCENES));
        $flowRun = FlowRun::reconstitute(FlowRunStatus::Active, 0, FlowRunStage::Scene, 1, 200, 'solo', ScenePart::Closing, 'wrap', 0, 200, [], null, null, 0, []);
        $full = Campaign::reconstitute(CampaignId::fromString('campaign-4'), 'user-1', 'Full', PinnedRelease::of('guided', 1, 'Guided'), self::at('09:00'), [Session::reconstitute(1, self::at('09:05'), $scenes)], [], 'chain', $flowRun);
        $this->campaigns->add($full);

        try {
            $this->complete(self::command('wrap', text: 'Done', campaignId: 'campaign-4'));
            self::fail('A scene was started in a full session.');
        } catch (CampaignLimitReached) {
            self::assertSame([], $this->journalOf('campaign-4'));
            self::assertEquals($full, $this->stored('campaign-4'));
        }
    }

    private function complete(CompleteFlowStep $command, int ...$rolls): void
    {
        $handler = new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(...$rolls), $this->clock);
        $handler($command);
    }

    private static function command(string $stepKey, ?string $text = null, ?string $optionKey = null, ?string $likelihood = null, ?int $chaosFactor = null, string $campaignId = 'campaign-1', string $userId = 'user-1'): CompleteFlowStep
    {
        return new CompleteFlowStep($campaignId, $userId, 'entry-1', $stepKey, $text, $optionKey, $likelihood, $chaosFactor);
    }
}
