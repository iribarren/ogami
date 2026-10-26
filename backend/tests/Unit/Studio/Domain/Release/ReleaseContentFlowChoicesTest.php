<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sheet effects on choice options and prompts, check sets and tables by Scene Type (ADR 0042),
 * run against valid/v2-flow-choices: the Town steps are the choice "learned", the prompt
 * "reflect", the check "defy" over grit and wits, and the table "trouble" with a map.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Steps::class)]
final class ReleaseContentFlowChoicesTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-flow-choices';

    private const array SHEET = ['kind' => 'sheet', 'field' => 'xp', 'op' => 'add', 'value' => 1];

    #[Test]
    public function theNewShapesAreValidAndKeepTheirCanonicalForm(): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys($release));
        /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $canonical */
        $canonical = $content->toArray();
        [$learned, $reflect, $defy, $trouble] = $canonical['sceneTypes'][0]['play'];

        self::assertSame(ReleaseContent::fromArray($release)->hash(), $content->hash(), 'The key order of the author does not change the hash.');
        /** @var list<array{effects: list<array<string, mixed>>}> $options */
        $options = $learned['options'];
        /** @var list<array<string, mixed>> $reflectEffects */
        $reflectEffects = $reflect['effects'];
        self::assertSame([self::SHEET], $options[0]['effects']);
        self::assertSame('hurt', $reflectEffects[0]['value']);
        self::assertSame(['grit', 'wits'], $defy['checks']);
        self::assertArrayNotHasKey('check', $defy);
        self::assertSame(['town' => 'street-trouble', 'wild' => 'wild-trouble'], $trouble['tableBySceneType']);
        self::assertSame(['key', 'kind', 'title', 'table', 'tableBySceneType', 'otherwise'], array_keys($trouble));
    }

    #[Test]
    public function aNullCheckOrChecksReadsLikeAnAbsentOne(): void
    {
        $single = ReleaseArrays::with(ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'sceneTypes.0.play.2.checks', null), 'sceneTypes.0.play.2.check', 'grit');
        $set = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'sceneTypes.0.play.2.check', null);

        /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $canonical */
        $canonical = ReleaseContent::fromArray($single)->toArray();

        self::assertSame('grit', $canonical['sceneTypes'][0]['play'][2]['check']);
        self::assertArrayNotHasKey('checks', $canonical['sceneTypes'][0]['play'][2]);
        self::assertSame(ReleaseContent::fromArray(ReleaseArrays::fixture(self::FIXTURE))->hash(), ReleaseContent::fromArray($set)->hash());
    }

    #[Test]
    public function aTableMapHashesTheSameWhateverItsAuthoredOrder(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'sceneTypes.0.play.3.tableBySceneType', ['town' => 'street-trouble', 'wild' => 'wild-trouble']);

        self::assertSame(ReleaseContent::fromArray(ReleaseArrays::fixture(self::FIXTURE))->hash(), ReleaseContent::fromArray($release)->hash());
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in the fixture, its new value and the start of the expected message
     */
    public static function refusals(): iterable
    {
        $at = 'sceneTypes[0].play';

        yield 'a sheet effect on the skip option' => ['sceneTypes.0.play.0.options.1.effects', [self::SHEET], $at.'[0].options[1].effects[0]: a sheet effect is not allowed on the option the skip names'];
        yield 'a sheet effect on the option another skip names' => ['sceneTypes.0.play.0.skip', 'yes', $at.'[0].options[0].effects[0]: a sheet effect is not allowed on the option the skip names'];
        yield 'a sheet effect in the own effects of a choice' => ['sceneTypes.0.play.0.effects', [self::SHEET], $at.'[0].effects[0]: a sheet effect is allowed only in '];
        yield 'a sheet effect in the own effects of a check' => ['sceneTypes.0.play.2.effects', [self::SHEET], $at.'[2].effects[0]: a sheet effect is allowed only in '];
        yield 'a sheet effect on an unknown field in a prompt' => ['sceneTypes.0.play.1.effects.0.field', 'ghost', $at.'[1].effects[0].field: unknown sheet field "ghost"'];
        yield 'a mark without an option in a choice option' => ['sceneTypes.0.play.0.options.0.effects', [['kind' => 'sheet', 'field' => 'wounds', 'op' => 'mark']], $at.'[0].options[0].effects[0].value: '];
        yield 'check and checks' => ['sceneTypes.0.play.2.check', 'grit', $at.'[2].checks: give either check or checks, not both'];
        yield 'neither check nor checks' => ['sceneTypes.0.play.2.checks', null, $at.'[2].check: required, give check or checks'];
        yield 'one check in the set' => ['sceneTypes.0.play.2.checks', ['grit'], $at.'[2].checks: at least 2 checks, 1 given'];
        yield 'eleven checks in the set' => ['sceneTypes.0.play.2.checks', array_fill(0, 11, 'grit'), $at.'[2].checks: at most 10 checks, 11 given'];
        yield 'a repeated check' => ['sceneTypes.0.play.2.checks', ['grit', 'grit'], $at.'[2].checks[1]: duplicate check "grit"'];
        yield 'an unknown check' => ['sceneTypes.0.play.2.checks', ['grit', 'ghost'], $at.'[2].checks[1]: unknown check "ghost"'];
        yield 'a check with other bands' => ['sceneTypes.0.play.2.checks', ['grit', 'luck'], $at.'[2].checks[1]: check "luck" has other bands than check "grit"'];
        yield 'a check that is not a key' => ['sceneTypes.0.play.2.checks', ['grit', 3], $at.'[2].checks[1]: must be a string'];
        yield 'a difficulty for checks that declare none' => ['sceneTypes.0.play.2.difficulty', 3, $at.'[2].difficulty: check "grit" declares no difficulty'];
        yield 'a band no check of the set has' => ['sceneTypes.0.play.2.outcomes.0.band', 'bad', $at.'[2].outcomes[0].band: unknown band "bad" of checks "grit", "wits"'];
        yield 'an empty table map' => ['sceneTypes.0.play.3.tableBySceneType', [], $at.'[3].tableBySceneType: at least 1 Scene Type, 0 given'];
        yield 'a table map that is a list' => ['sceneTypes.0.play.3.tableBySceneType', ['wild-trouble'], $at.'[3].tableBySceneType: must be an object'];
        yield 'an unknown Scene Type in the map' => ['sceneTypes.0.play.3.tableBySceneType', ['ghost' => 'wild-trouble'], $at.'[3].tableBySceneType.ghost: unknown Scene Type "ghost"'];
        yield 'an unknown table in the map' => ['sceneTypes.0.play.3.tableBySceneType', ['wild' => 'ghost'], $at.'[3].tableBySceneType.wild: unknown oracle table "ghost"'];
        yield 'a table in the map that is not a key' => ['sceneTypes.0.play.3.tableBySceneType', ['wild' => 7], $at.'[3].tableBySceneType.wild: must be a string'];
        yield 'branches with the map' => ['sceneTypes.0.play.3.branches', [['entry' => 'a-storm']], $at.'[3].branches: not allowed with tableBySceneType'];
    }

    /**
     * @param non-empty-string $expectedMessage
     */
    #[Test]
    #[DataProvider('refusals')]
    public function theNewRulesRefuseWhatBreaksThem(string $path, mixed $value, string $expectedMessage): void
    {
        $release = null === $value
            ? ReleaseArrays::without(ReleaseArrays::fixture(self::FIXTURE), $path)
            : ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), $path, $value);

        try {
            ReleaseContent::fromArray($release);
            self::fail('The release must be refused.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringStartsWith($expectedMessage, $exception->getMessage());
        }
    }

    #[Test]
    public function aDifficultyMustSuitEveryCheckOfTheSet(): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = ReleaseArrays::with($release, 'checks.0.difficulty', ['label' => 'Target', 'default' => 7, 'min' => 5, 'max' => 9]);
        $release = ReleaseArrays::with($release, 'checks.1.difficulty', ['label' => 'Target', 'default' => 10, 'min' => 8, 'max' => 12]);

        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::with($release, 'sceneTypes.0.play.2.difficulty', 8))->hash(), 'A value both ranges allow is valid.');

        try {
            ReleaseContent::fromArray(ReleaseArrays::with($release, 'sceneTypes.0.play.2.difficulty', 6));
            self::fail('6 is below the minimum of "wits".');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringStartsWith('sceneTypes[0].play[2].difficulty: must be at least 8, the difficulty min of check "wits"', $exception->getMessage());
        }
    }

    #[Test]
    public function aTableOfTheMapIsRolledByTheFlowThatReachesTheStep(): void
    {
        $flow = ['key' => 'main', 'name' => 'Main', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['town']]]]];
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = ReleaseArrays::with($release, 'trackers', [['key' => 'dread', 'name' => 'Dread', 'kind' => 'clock', 'segments' => 6]]);
        $release = ReleaseArrays::with($release, 'oracles.tables.1.entries.0.effects', [['kind' => 'tracker', 'tracker' => 'dread', 'op' => 'add', 'value' => 1]]);
        $release = ReleaseArrays::with($release, 'flows', [$flow]);

        try {
            ReleaseContent::fromArray($release);
            self::fail('The flow does not declare the tracker the effect of a mapped table reads.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringContainsString('"dread" is not one of the trackers of flow "main"', $exception->getMessage());
        }

        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::with($release, 'flows.0.trackers', ['dread']))->hash());
    }
}
