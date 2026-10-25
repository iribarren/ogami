<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the checks of a release (ADR 0024) and re-checks what Studio
 * checks on publish. The base release has a sheet (hands, a derived edge, hp), a clock "alarm" and
 * the checks "skill" (modifier, difficulty, bands over the difficulty and the alarm) and "luck".
 * A check step is read with its fixed difficulty and Outcomes, and checked against its check.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(Check::class)]
final class GameSystemReleaseTranslatorChecksTest extends TestCase
{
    private const string UNREADABLE = 'GameSystem "minimal" v1 cannot be read by Play: ';

    /**
     * @param array<string, mixed> $changes replaced in the skill check; null leaves a property out
     *
     * @return array<string, mixed>
     */
    private static function skill(array $changes = []): array
    {
        return array_replace([
            'key' => 'skill',
            'label' => 'Skill check',
            'hint' => 'A stat against a Difficulty Value.',
            'dice' => '1d10',
            'modifier' => '{hands} + {edge}',
            'difficulty' => ['label' => 'Difficulty Value', 'default' => 13, 'min' => 9, 'max' => 29],
            'bands' => [
                ['key' => 'fail', 'label' => 'Fail', 'upTo' => ['formula' => '{check:difficulty} - 1']],
                ['key' => 'success', 'label' => 'Success', 'upTo' => ['tracker' => 'alarm']],
                ['key' => 'crit', 'label' => 'Critical success'],
            ],
        ], $changes);
    }

    /**
     * @return array<string, mixed>
     */
    private function luck(): array
    {
        return ['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'bands' => [['key' => 'bad', 'label' => 'Bad', 'upTo' => 20], ['key' => 'good', 'label' => 'Good']]];
    }

    /**
     * @param list<array<string, mixed>>|mixed $checks
     */
    private function translate(mixed $checks): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('valid/v2-minimal');
        $content = ReleaseArrays::with($content, 'trackers', [['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6]]);
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [
            ['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'default' => 4],
            ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
            ['key' => 'edge', 'label' => 'Edge', 'type' => 'derived', 'formula' => '{hands} / 2'],
            ['key' => 'hp', 'label' => 'HP', 'type' => 'track'],
        ]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'checks', $checks);

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content, schemaVersion: 2));
    }

    #[Test]
    public function itReadsTheChecksInDefinitionOrder(): void
    {
        $snapshot = $this->translate([self::skill(), $this->luck()]);

        self::assertEquals([
            new Check(
                'skill',
                'Skill check',
                'A stat against a Difficulty Value.',
                DiceExpression::fromString('1d10'),
                Formula::fromString('{hands} + {edge}'),
                new CheckDifficulty('Difficulty Value', 13, 9, 29),
                [
                    new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')),
                    new CheckBand('success', 'Success', Formula::fromString('{tracker:alarm}')),
                    new CheckBand('crit', 'Critical success'),
                ],
            ),
            new Check('luck', 'Luck', null, DiceExpression::fromString('d%'), null, null, [new CheckBand('bad', 'Bad', 20), new CheckBand('good', 'Good')]),
        ], $snapshot->checks());
        self::assertSame('luck', $snapshot->check('luck')?->key);
    }

    #[Test]
    public function aReleaseWithoutChecksHasNone(): void
    {
        self::assertSame([], $this->translate([])->checks());

        $content = ReleaseViews::fixtureContent('valid/v2-minimal');
        unset($content['checks']);
        self::assertSame([], new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content, schemaVersion: 2))->checks());
    }

    #[Test]
    public function aSchemaVersion1ReleaseHasNoChecks(): void
    {
        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent()));

        self::assertSame([], $snapshot->checks());
    }

    #[Test]
    public function theContractDocExampleExposesItsCheck(): void
    {
        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('valid/v2-contract-doc-example')));

        $check = $snapshot->check('slip-past');
        self::assertInstanceOf(Check::class, $check);
        self::assertSame(['2d6', 'Alert level', 7], [$check->dice->notation(), $check->difficulty?->label, $check->difficulty?->default]);
        self::assertSame(['caught', 'through'], array_map(static fn (CheckBand $band): string => $band->key, $check->bands));
        $step = $snapshot->sceneType('infiltration')?->play->step('sneak-by');
        self::assertInstanceOf(CheckStep::class, $step);
        self::assertSame(['slip-past', null, ['caught']], [$step->check, $step->difficulty, array_keys($step->outcomes)]);
    }

    #[Test]
    public function itReadsACheckStepWithItsFixedDifficultyAndItsOutcomesByBand(): void
    {
        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('valid/v2-checks')));

        self::assertEquals(
            new CheckStep(
                'pick-lock', 'Pick the lock', null, null, false, null, [],
                'skill',
                Formula::fromString('{tracker:alarm}+9'),
                ['fail' => new Outcome(null, [new TrackerEffect('alarm', TrackerOperation::Add, 1)]), 'crit' => new Outcome('end')],
            ),
            $snapshot->sceneType('break-in')?->play->step('pick-lock'),
        );
    }

    #[Test]
    public function aCheckStepWithoutDifficultyOrOutcomesIsRead(): void
    {
        $step = $this->checkStepIn(['difficulty' => null, 'outcomes' => null]);

        self::assertEquals(new CheckStep('pick-lock', 'Pick the lock', null, null, false, null, [], 'skill', null, []), $step);
    }

    #[Test]
    public function aLiteralDifficultyWithinTheRangeOfTheCheckIsRead(): void
    {
        $step = $this->checkStepIn(['difficulty' => 29]);

        self::assertSame(29, $step->difficulty);
    }

    /**
     * @param array<string, mixed> $changes
     * @param non-empty-string     $message
     */
    #[Test]
    #[DataProvider('malformedCheckSteps')]
    public function aCheckStepThatBreaksARuleOfStudioIsRefused(array $changes, string $message): void
    {
        try {
            $this->checkStepIn($changes);
            self::fail('The release was read.');
        } catch (InvalidGameSystemRelease $invalid) {
            self::assertStringEndsWith($message, $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}> the changes to the step of the fixture and the message
     */
    public static function malformedCheckSteps(): iterable
    {
        $at = 'sceneTypes[0].play[0]';

        yield 'an unknown check' => [['check' => 'ghost'], $at.'.check: unknown check "ghost".'];
        yield 'a check that is not a string' => [['check' => 5], $at.'.check: must be a string.'];
        yield 'a difficulty for a check that declares none' => [['check' => 'luck', 'difficulty' => 5, 'outcomes' => null], $at.'.difficulty: check "luck" declares no difficulty.'];
        yield 'a literal under the min' => [['difficulty' => 8], $at.'.difficulty: must be at least 9, the difficulty min of check "skill", 8 given.'];
        yield 'a literal over the max' => [['difficulty' => 30], $at.'.difficulty: must be at most 29, the difficulty max of check "skill", 30 given.'];
        yield 'a literal beyond the limits of the contract' => [['difficulty' => 1001], $at.'.difficulty: must be between -1000 and 1000.'];
        yield 'a difficulty on an unknown Tracker' => [['difficulty' => ['tracker' => 'ghost']], $at.'.difficulty: unknown tracker "ghost".'];
        yield 'a difficulty that is not a value' => [['difficulty' => 'hard'], $at.'.difficulty: must be an integer, {"tracker": key} or {"formula": string}.'];
        yield 'outcomes that are not a list' => [['outcomes' => 'fail'], $at.'.outcomes: must be a list.'];
        yield 'an outcome that is not an object' => [['outcomes' => ['fail']], $at.'.outcomes[0]: must be an object.'];
        yield 'an outcome band that is not a string' => [['outcomes' => [['band' => 5]]], $at.'.outcomes[0].band: must be a string.'];
        yield 'an outcome on an unknown band' => [['outcomes' => [['band' => 'ghost']]], $at.'.outcomes[0].band: unknown band "ghost" of check "skill".'];
        yield 'two outcomes on a band' => [['outcomes' => [['band' => 'fail'], ['band' => 'fail']]], $at.'.outcomes[1].band: duplicate outcome band "fail".'];
    }

    /**
     * @param array<string, mixed> $changes replaced in the check step of the fixture "v2-checks"; null leaves a property out
     */
    private function checkStepIn(array $changes): CheckStep
    {
        /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $content */
        $content = ReleaseViews::fixtureContent('valid/v2-checks');
        $step = array_filter(array_replace($content['sceneTypes'][0]['play'][0], $changes), static fn (mixed $value): bool => null !== $value);
        $content['sceneTypes'][0]['play'][0] = $step;
        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
        $read = $snapshot->sceneType('break-in')?->play->step('pick-lock');

        return $read instanceof CheckStep ? $read : self::fail('No check step.');
    }

    /**
     * @return iterable<string, array{mixed, string}> the checks and the message
     */
    public static function malformedChecks(): iterable
    {
        $bands = static fn (array $first, array ...$more): array => ['bands' => [$first, ...$more]];
        $last = ['key' => 'last', 'label' => 'Last'];
        $band = static fn (string $key, mixed $upTo): array => ['key' => $key, 'label' => ucfirst($key), 'upTo' => $upTo];

        yield 'checks that are not a list' => ['skill', 'checks: must be a list.'];
        yield 'a check that is not an object' => [['skill'], 'checks[0]: must be an object.'];
        yield 'more than 50 checks' => [array_map(static fn (int $n): array => self::skill(['key' => 'c'.$n]), range(1, 51)), 'checks: at most 50 checks.'];
        yield 'an unknown property' => [[self::skill(['step' => 1])], 'checks[0].step: unknown property.'];
        yield 'a key that breaks the pattern' => [[self::skill(['key' => 'Skill'])], 'checks[0].key: must be 1 to 64 characters among a-z, 0-9 and "-".'];
        yield 'a key that is not a string' => [[self::skill(['key' => 5])], 'checks[0].key: must be a string.'];
        yield 'a duplicate key' => [[self::skill(), self::skill()], 'checks[1].key: duplicate check key "skill".'];
        yield 'a blank label' => [[self::skill(['label' => ' '])], 'checks[0].label: must not be blank.'];
        yield 'a label over 100 characters' => [[self::skill(['label' => str_repeat('a', 101)])], 'checks[0].label: must be at most 100 characters.'];
        yield 'a hint over 500 characters' => [[self::skill(['hint' => str_repeat('a', 501)])], 'checks[0].hint: must be at most 500 characters.'];
        yield 'dice that are not a string' => [[self::skill(['dice' => 6])], 'checks[0].dice: must be a string.'];
        yield 'dice that do not parse' => [[self::skill(['dice' => '1d'])], 'checks[0].dice: invalid dice notation: Unexpected end of the dice expression; expected the number of sides or "%".'];
        yield 'dice over 100 characters' => [[self::skill(['dice' => str_repeat('1+', 60).'1'])], 'checks[0].dice: invalid dice notation: A dice expression has at most 100 characters, 121 given.'];
        yield 'a modifier that does not parse' => [[self::skill(['modifier' => '{hands} +'])], 'checks[0].modifier: Unexpected end of the formula; expected a number, a reference, "min", "max", "-" or "(".'];
        yield 'a modifier reading an unknown field' => [[self::skill(['modifier' => '{ghost}'])], 'checks[0].modifier: {ghost}: unknown field "ghost".'];
        yield 'a modifier reading a text field' => [[self::skill(['modifier' => '{role}'])], 'checks[0].modifier: {role}: field "role" is a text or list field, a formula reads number, track and derived fields only.'];
        yield 'a modifier reading an unknown tracker' => [[self::skill(['modifier' => '{tracker:nope}'])], 'checks[0].modifier: {tracker:nope}: unknown tracker "nope".'];
        yield 'a modifier reading the difficulty' => [[self::skill(['modifier' => '{check:difficulty}'])], 'checks[0].modifier: {check:difficulty}: a check reference is read only by the bands of a check.'];
        yield 'a difficulty that is not an object' => [[self::skill(['difficulty' => 5])], 'checks[0].difficulty: must be an object.'];
        yield 'a difficulty with an unknown property' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 1, 'step' => 1]])], 'checks[0].difficulty.step: unknown property.'];
        yield 'a difficulty label over 60 characters' => [[self::skill(['difficulty' => ['label' => str_repeat('a', 61), 'default' => 13]])], 'checks[0].difficulty.label: must be at most 60 characters.'];
        yield 'a difficulty without a default' => [[self::skill(['difficulty' => ['label' => 'DV']])], 'checks[0].difficulty.default: required.'];
        yield 'a difficulty default over 1,000' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 1001]])], 'checks[0].difficulty.default: must be between -1000 and 1000.'];
        yield 'a difficulty min under -1,000' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 0, 'min' => -1001]])], 'checks[0].difficulty.min: must be between -1000 and 1000.'];
        yield 'a difficulty max that is not an integer' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 0, 'max' => '9']])], 'checks[0].difficulty.max: must be an integer.'];
        yield 'a difficulty min above the default' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 5, 'min' => 6]])], 'checks[0].difficulty.min: must be at most default (5).'];
        yield 'a difficulty max below the default' => [[self::skill(['difficulty' => ['label' => 'DV', 'default' => 5, 'max' => 4]])], 'checks[0].difficulty.max: must be at least default (5).'];
        yield 'bands that are not a list' => [[self::skill(['bands' => 'none'])], 'checks[0].bands: must be a list.'];
        yield 'no band' => [[self::skill(['bands' => []])], 'checks[0].bands: 1 to 10 bands.'];
        yield 'more than 10 bands' => [[self::skill(['bands' => [...array_map(static fn (int $n): array => $band('b'.$n, $n), range(1, 10)), $last]])], 'checks[0].bands: 1 to 10 bands.'];
        yield 'a band with an unknown property' => [[self::skill($bands($last + ['next' => 'x']))], 'checks[0].bands[0].next: unknown property.'];
        yield 'a band key that breaks the pattern' => [[self::skill($bands(['key' => 'A', 'label' => 'A']))], 'checks[0].bands[0].key: must be 1 to 64 characters among a-z, 0-9 and "-".'];
        yield 'a duplicate band key' => [[self::skill($bands($band('a', 1), ['key' => 'a', 'label' => 'A again']))], 'checks[0].bands[1].key: duplicate band key "a".'];
        yield 'a blank band label' => [[self::skill($bands(['key' => 'a', 'label' => '']))], 'checks[0].bands[0].label: must not be blank.'];
        yield 'a bound that is a string' => [[self::skill($bands($band('a', 'high'), $last))], 'checks[0].bands[0].upTo: must be an object.'];
        yield 'an empty bound' => [[self::skill($bands($band('a', []), $last))], 'checks[0].bands[0].upTo: must be an integer, {"tracker": key} or {"formula": string}.'];
        yield 'a bound with a tracker and a formula' => [[self::skill($bands($band('a', ['formula' => '1', 'tracker' => 'alarm']), $last))], 'checks[0].bands[0].upTo.tracker: unknown property.'];
        yield 'a bound with another property' => [[self::skill($bands($band('a', ['tracker' => 'alarm', 'step' => 1]), $last))], 'checks[0].bands[0].upTo.step: unknown property.'];
        yield 'a bound tracker that breaks the key rule' => [[self::skill($bands($band('a', ['tracker' => 'Alarm']), $last))], 'checks[0].bands[0].upTo.tracker: must be 1 to 64 characters among a-z, 0-9 and "-".'];
        yield 'a bound naming an unknown tracker' => [[self::skill($bands($band('a', ['tracker' => 'nope']), $last))], 'checks[0].bands[0].upTo.tracker: {tracker:nope}: unknown tracker "nope".'];
        yield 'a bound formula that does not parse' => [[self::skill($bands($band('a', ['formula' => '1 +']), $last))], 'checks[0].bands[0].upTo.formula: Unexpected end of the formula; expected a number, a reference, "min", "max", "-" or "(".'];
        yield 'a bound formula reading an unknown field' => [[self::skill($bands($band('a', ['formula' => '{ghost}']), $last))], 'checks[0].bands[0].upTo.formula: {ghost}: unknown field "ghost".'];
        yield 'a bound formula reading another check value' => [[self::skill($bands($band('a', ['formula' => '{check:other}']), $last))], 'checks[0].bands[0].upTo.formula: {check:other}: unknown check value "other", only "difficulty" can be read.'];
        yield 'a bound reading the difficulty of a check without one' => [[self::skill(['difficulty' => null])], 'checks[0].bands[0].upTo.formula: {check:difficulty}: the check declares no difficulty.'];
        yield 'a last band with a bound' => [[self::skill($bands($band('a', 1)))], 'checks[0].bands: The last band of check "skill" catches the rest and has no upper bound.'];
        yield 'a band but the last without a bound' => [[self::skill($bands(['key' => 'a', 'label' => 'A'], $last))], 'checks[0].bands: Band "a" of check "skill" needs an upper bound; only the last band has none.'];
        yield 'literal bounds that do not increase' => [[self::skill($bands($band('a', 5), $band('b', 5), $last))], 'checks[0].bands: Band "b" of check "skill" must be above the previous upper bound 5.'];
    }

    #[Test]
    #[DataProvider('malformedChecks')]
    public function itFailsClosedOnChecksStudioWouldHaveRejected(mixed $checks, string $message): void
    {
        try {
            $this->translate($checks);
            self::fail('The checks are malformed.');
        } catch (InvalidGameSystemRelease $invalid) {
            self::assertSame(self::UNREADABLE.$message, $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the check and the message: Play refuses the shapes of ADR 0041 until the slices that play them
     */
    public static function unsupportedDicePoolShapes(): iterable
    {
        $pool = ['count' => 3, 'sides' => 6, 'successAt' => 6];

        yield 'a pool beside dice' => [self::skill(['pool' => $pool]), 'checks[0].pool: unknown property.'];
        yield 'a pool instead of dice' => [self::skill(['dice' => null, 'modifier' => null, 'pool' => $pool]), 'checks[0].pool: unknown property.'];
        yield 'a pool with a formula count' => [self::skill(['dice' => null, 'modifier' => null, 'pool' => ['count' => ['formula' => '{hands}']] + $pool]), 'checks[0].pool: unknown property.'];
        yield 'a push' => [self::skill(['push' => ['label' => 'Push']]), 'checks[0].push: unknown property.'];
        yield 'a push with a pool' => [self::skill(['dice' => null, 'modifier' => null, 'pool' => $pool, 'push' => ['label' => 'Push']]), 'checks[0].pool: unknown property.'];
    }

    /**
     * @param array<string, mixed> $check
     */
    #[Test]
    #[DataProvider('unsupportedDicePoolShapes')]
    public function itStillRefusesPoolsAndPushes(array $check, string $message): void
    {
        try {
            $this->translate([$check]);
            self::fail('Play does not play pools or pushes yet.');
        } catch (InvalidGameSystemRelease $invalid) {
            self::assertSame(self::UNREADABLE.$message, $invalid->getMessage());
        }
    }
}
