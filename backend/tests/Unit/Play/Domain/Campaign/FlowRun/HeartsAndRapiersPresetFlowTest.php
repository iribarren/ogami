<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `hearts-and-rapiers` preset played through the Campaign: the Setup that names the
 * villain and the goal, a scene whose move check marks XP on a miss and advances the Countdown, and
 * the Climax. The moves are 2d6 checks with a miss up to 6, a partial hit up to 9 and a full hit
 * above; the dice are scripted as two numbers that add up to the total.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class HeartsAndRapiersPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/hearts-and-rapiers.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function theSetupNamesTheVillainAndTheGoalFromTheAnswers(): void
    {
        $campaign = $this->afterTheSetup();

        self::assertSame([['Baron Vane', ['villain']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        self::assertSame(['Free the prisoner'], array_map(static fn (Thread $thread): string => $thread->name(), $campaign->threads()));
        $campaign->endFlowScene(2, $this->release, self::at());

        // Past the Setup, the player picks the first scene of the Scenes phase.
        $view = $campaign->flowRunView($this->release);
        self::assertSame('scenes', $view?->progress?->phaseKey);
        self::assertNotNull($view->pick);
    }

    #[Test]
    public function aMissedMoveMarksXpAndAdvancesTheCountdown(): void
    {
        $campaign = $this->inAScene();

        $changes = $campaign->completeFlowStep('check-1', $this->checked($campaign, 'check-1', 'defy-disaster-daring', 3, 3), $this->release, self::at());

        self::assertSame([['xp', 0, 1]], array_map(static fn (SheetChange $change): array => [$change->field, $change->before, $change->after], $changes));
        self::assertSame(1, $campaign->trackerValue($this->release->tracker('countdown') ?? self::fail('No countdown.')));
    }

    #[Test]
    public function aHitDoesNotMarkXpButStillAdvancesTheCountdown(): void
    {
        $campaign = $this->inAScene();

        $changes = $campaign->completeFlowStep('check-1', $this->checked($campaign, 'check-1', 'defy-disaster-daring', 5, 5), $this->release, self::at());

        self::assertSame([], $changes);
        self::assertSame(1, $campaign->trackerValue($this->release->tracker('countdown') ?? self::fail('No countdown.')));
    }

    #[Test]
    public function aMissedConfrontationMarksXp(): void
    {
        $campaign = $this->inAScene();
        $campaign->completeFlowStep('check-1', $this->checked($campaign, 'check-1', 'defy-disaster-daring', 5, 5), $this->release, self::at());
        $campaign->endFlowScene(3, $this->release, self::at());
        $campaign->moveOn('scenes', $this->release, self::at());

        // The confrontation is the opening of the Climax's one Swashbuckle scene, not of the phase.
        $progress = $campaign->flowRunView($this->release)?->progress;
        self::assertSame(['climax', 'Swashbuckle', ScenePart::SceneOpening, 'check-1'], [$progress?->phaseKey, $progress->sceneType ?? null, $progress->part ?? null, self::position($campaign)[1]]);
        $changes = $campaign->completeFlowStep('check-1', $this->checked($campaign, 'check-1', 'fight-daring', 2, 3), $this->release, self::at());

        self::assertSame([['xp', 0, 1]], array_map(static fn (SheetChange $change): array => [$change->field, $change->before, $change->after], $changes));
    }

    /**
     * @return iterable<string, array{string, string, ?string}> the move, its stat and the Condition that costs it
     */
    public static function moves(): iterable
    {
        yield 'fight (daring)' => ['fight-daring', 'daring', null];
        yield 'fight (grace)' => ['fight-grace', 'grace', 'frightened'];
        foreach (['daring', 'grace', 'heart', 'wit', 'spirit'] as $stat) {
            yield 'defy disaster ('.$stat.')' => ['defy-disaster-'.$stat, $stat, 'hopeless'];
        }
        yield 'entice' => ['entice', 'heart', 'insecure'];
        yield 'figure out a person' => ['figure-out-a-person', 'wit', 'angry'];
        yield 'emotional support (heart)' => ['emotional-support-heart', 'heart', 'guilty'];
        yield 'emotional support (spirit)' => ['emotional-support-spirit', 'spirit', 'guilty'];
        yield 'call on a toxic power' => ['call-on-a-toxic-power', 'spirit', null];
    }

    #[Test]
    #[DataProvider('moves')]
    public function everyMoveIsAMissUpToSixAPartialHitUpToNineAndAFullHitAbove(string $check, string $stat, ?string $condition): void
    {
        $bands = [];
        foreach ([[3, 3], [3, 4], [4, 5], [5, 5]] as [$first, $second]) {
            $bands[$first + $second] = $this->perform($check, [], $first, $second)->band->key;
        }

        self::assertSame([6 => 'miss', 7 => 'partial', 9 => 'partial', 10 => 'full'], $bands);
    }

    #[Test]
    #[DataProvider('moves')]
    public function aMarkedConditionCostsTwoOnItsMove(string $check, string $stat, ?string $condition): void
    {
        $marked = null === $condition ? [$stat => 1] : [$stat => 1, $condition => 1];

        $result = $this->perform($check, $marked, 3, 4);

        // The stat adds 1 and the Condition takes 2, so grace 1 with frightened 1 is a modifier of -1.
        self::assertSame(null === $condition ? 1 : -1, $result->modifier);
        self::assertSame(null === $condition ? 8 : 6, $result->total);
        self::assertSame(null === $condition ? 'partial' : 'miss', $result->band->key);
    }

    /**
     * @param array<string, int> $values the sheet values the character sets, the rest keep their defaults
     */
    private function perform(string $check, array $values, int $first, int $second): \App\Play\Domain\GameSystem\Check\CheckResult
    {
        $sheetValues = SheetValues::of($this->release->sheet(), $values, []);

        return ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($first, $second), $sheetValues, null, 0);
    }

    /**
     * The hero rolling the check with the two dice scripted.
     */
    private function checked(Campaign $campaign, string $stepKey, string $check, int $first, int $second): StepResult
    {
        $campaign->flowCheckDifficulty($stepKey, 'hero', null, $this->release, self::at());
        $hero = $campaign->character(CharacterId::fromString('hero')) ?? self::fail('No hero.');
        $values = SheetValues::of($this->release->sheet(), $hero->values(), $campaign->trackerValuesOf($this->release));

        return StepResult::check('hero', ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($first, $second), $values, null, 0));
    }

    private function rolled(string $table, int $total): StepResult
    {
        return StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total)));
    }

    /**
     * A campaign through the Setup: the hero, the toxic power and story element tables, the villain and the goal.
     */
    private function afterTheSetup(): Campaign
    {
        $campaign = self::guided($this->release, 'one-shot');
        $campaign->startSession(self::at(), $this->release);
        $campaign->addCharacter(CharacterId::fromString('hero'), 'Rosalind', [], [], ['playbook' => 'The Adventurer'], $this->release);
        $campaign->completeFlowStep('character-1', StepResult::character('hero'), $this->release, self::at());
        $campaign->completeFlowStep('table-1', $this->rolled('toxic-power', 1), $this->release, self::at());
        $campaign->completeFlowStep('table-2', $this->rolled('story-element', 1), $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('Baron Vane'), $this->release, self::at());
        $campaign->completeFlowStep('prompt-2', self::prompt('Free the prisoner'), $this->release, self::at());

        return $campaign;
    }

    /**
     * A campaign in a Swashbuckle scene of the Scenes phase, at its move check.
     */
    private function inAScene(): Campaign
    {
        $campaign = $this->afterTheSetup();
        $campaign->endFlowScene(2, $this->release, self::at());
        $campaign->pickSceneType('swashbuckle', $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('Duel on the quay'), $this->release, self::at());
        $campaign->completeFlowStep('table-1', $this->rolled('romantic-complication', 1), $this->release, self::at());

        return $campaign;
    }
}
