<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The character step while a FlowRun plays: the player names a Character of the campaign by id, its
 * current name is the answer ("{step:hero}") and it joins like a one-item pick. The test release has
 * Scene Types "intro" (a mandatory character step, then a prompt reading "{step:hero}") and
 * "optional" (a skippable one), and a world turn that asks for a Character.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(StepResult::class)]
final class FlowRunCharacterStepTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function theStepCompletesWithTheNameOfTheCharacterAndItJoinsTheScenesCast(): void
    {
        $campaign = $this->inScene('intro');

        $campaign->completeFlowStep('hero', StepResult::character('ada'), $this->release, self::at());

        $run = self::flowRunOf($campaign);
        self::assertSame([['setup', 'greet'], ['hero' => 'Ada'], 'Hello Ada'], [self::position($campaign), $run->answers(), $campaign->flowRunView($this->release)?->step?->title]);
        self::assertSame([['ada'], []], $this->joined($campaign));
        self::assertSame(StepKind::Character, StepResult::character('ada')->kind);
    }

    #[Test]
    public function theAnswerKeepsTheNameItWasGivenWithEvenIfTheCharacterIsRenamedLater(): void
    {
        $campaign = $this->inScene('intro');
        $campaign->completeFlowStep('hero', StepResult::character('ada'), $this->release, self::at());
        $campaign->editCharacter(CharacterId::fromString('ada'), 'Ada Vega', [], [], [], $this->release);

        self::assertSame('Hello Ada', $campaign->flowRunView($this->release)?->step?->title);
    }

    #[Test]
    #[DataProvider('refusedResults')]
    public function aResultThatDoesNotNameACharacterChangesNothing(StepResult $result, string $message): void
    {
        $campaign = $this->inScene('intro');
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('hero', $result, $this->release, self::at());
            self::fail('The step was completed.');
        } catch (InvalidStepResult $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    /**
     * @return iterable<string, array{StepResult, string}>
     */
    public static function refusedResults(): iterable
    {
        yield 'an unknown id' => [StepResult::character('ghost'), 'Character step "hero" has no Character "ghost" in the campaign.'];
        yield 'a blank id' => [StepResult::character(''), 'Character step "hero" has no Character "" in the campaign.'];
        yield 'a result of another kind' => [StepResult::prompt('Ada'), 'Step "hero" is a character step: a prompt result does not complete it.'];
    }

    #[Test]
    public function aMandatoryStepCannotBeSkippedButAnOptionalOneCan(): void
    {
        $campaign = $this->inScene('intro');
        try {
            $campaign->skipFlowStep('hero', $this->release, self::at());
            self::fail('A mandatory step was skipped.');
        } catch (StepCannotBeSkipped) {
        }

        $optional = $this->inScene('optional');
        $optional->skipFlowStep('maybe', $this->release, self::at());

        self::assertSame([['setup', 'greet'], [], [[], []]], [self::position($optional), self::flowRunOf($optional)->answers(), $this->joined($optional)]);
    }

    #[Test]
    public function aStepInAHookAlsoJoinsTheSessionsPartyKeepingItsOrder(): void
    {
        $campaign = $this->atTheWorldTurn();
        $campaign->editCurrentSessionParty([CharacterId::fromString('bo')]);

        $campaign->completeFlowStep('who', StepResult::character('ada'), $this->release, self::at());
        $campaign->completeFlowStep('again', StepResult::character('bo'), $this->release, self::at());

        self::assertSame([['ada', 'bo'], ['bo', 'ada']], $this->joined($campaign));
        self::assertSame(2, $campaign->currentScene()?->number(), 'The hook Scene holds the cast.');
    }

    /**
     * @return array{list<string>, list<string>} the current Scene's Characters and the session's party
     */
    private function joined(Campaign $campaign): array
    {
        return [$this->ids($campaign->currentScene()?->characters() ?? []), $this->ids($campaign->currentSession()?->party() ?? [])];
    }

    /**
     * @param list<CharacterId> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (CharacterId $id): string => $id->toString(), $ids);
    }

    private function campaign(): Campaign
    {
        $campaign = self::guided($this->release, 'play');
        foreach ([['ada', 'Ada'], ['bo', 'Bo']] as [$id, $name]) {
            $campaign->addCharacter(CharacterId::fromString($id), $name, [], [], [], $this->release);
        }

        $campaign->startSession(self::at(), $this->release);

        return $campaign;
    }

    private function inScene(string $sceneType): Campaign
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType($sceneType, $this->release, self::at());

        return $campaign;
    }

    private function atTheWorldTurn(): Campaign
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('quiet', $this->release, self::at());
        $campaign->completeFlowStep('quiet', self::prompt('ok'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'character-step', 'name' => 'Character step'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [],
            'sceneTypes' => [
                $sceneType('intro', [['key' => 'hero', 'kind' => 'character', 'title' => 'Who are you?', 'mandatory' => true], $prompt('greet', 'Hello {step:hero}')]),
                $sceneType('optional', [['key' => 'maybe', 'kind' => 'character', 'title' => 'Anyone?', 'mandatory' => false], $prompt('greet', 'Hello')]),
                $sceneType('quiet', [$prompt('quiet', 'Quiet')]),
            ],
            'flows' => [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['intro', 'optional', 'quiet']], 'worldTurn' => [
                    ['key' => 'who', 'kind' => 'character', 'title' => 'Who stirs?'],
                    ['key' => 'again', 'kind' => 'character', 'title' => 'And who else?'],
                ]],
            ]]],
            'checks' => [],
        ];
    }
}
