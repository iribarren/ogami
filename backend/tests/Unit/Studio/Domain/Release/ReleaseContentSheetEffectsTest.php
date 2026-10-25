<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Effects;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sheet effects of schema version 2 (ADR 0027): additive, so releases without them keep their
 * canonical form and hash; the agreement test covers each rule, this one the canonical form, the
 * hashes and the trackers a sheet formula reads.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Effects::class)]
final class ReleaseContentSheetEffectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> the fixture and the hash it had before sheet effects existed
     */
    public static function existingReleases(): iterable
    {
        yield 'checks' => ['valid/v2-checks', '53bcf9f8648adcfeea90430e63a4cc730a244ac13c6ceb41ada7b1274ce91283'];
        yield 'every part' => ['valid/v2-every-part', 'd4ea555f0fa39a6c88d818de49ac5e60564559b1995ab0ac76febbc9c834a7b5'];
        yield 'sheet' => ['valid/v2-sheet', '80e866bbc910b09ca4b4b6ae2d6d8348e776e5ca8573c300f84a46bbf4d9a1c6'];
        yield 'inspiration' => ['valid/v2-inspiration', '8a00bb5152cd767147790a16c4e209f4ec5dd43d6371b2c3e913752b6a8460ff'];
    }

    #[Test]
    #[DataProvider('existingReleases')]
    public function releasesWithoutSheetEffectsKeepTheirHash(string $fixture, string $hash): void
    {
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->hash());
    }

    #[Test]
    public function sheetEffectsKeepTheirPropertyOrderAndTheirValueForm(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-sheet-effects');
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys($release));
        /** @var list<array{setup: list<array{effects: list<array<string, mixed>>}>, play: list<array{outcomes: list<array{effects: list<array<string, mixed>>}>}>}> $sceneTypes */
        $sceneTypes = $content->toArray()['sceneTypes'];

        self::assertSame(ReleaseContent::fromArray($release)->hash(), $content->hash());
        self::assertSame(['kind', 'field', 'op', 'value'], array_keys($sceneTypes[0]['setup'][0]['effects'][0]));
        self::assertSame(
            [1, ['dice' => '1d6'], ['formula' => '{bonus} - {tracker:dread}']],
            [$sceneTypes[0]['play'][0]['outcomes'][1]['effects'][0]['value'], $sceneTypes[0]['play'][0]['outcomes'][0]['effects'][0]['value'], $sceneTypes[0]['play'][0]['outcomes'][0]['effects'][2]['value']],
        );
    }

    #[Test]
    public function aSheetFormulaReadsOnlyTheTrackersOfTheFlow(): void
    {
        $flow = static fn (array $trackers): array => ['key' => 'main', 'name' => 'Main', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => $trackers, 'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]]]];
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-sheet-effects'), 'flows', [$flow(['dread'])]);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash(), 'A tracker the flow declares is accepted.');

        try {
            ReleaseContent::fromArray(ReleaseArrays::with($release, 'flows', [$flow([])]));
            self::fail('The flow does not declare the tracker the sheet effect reads.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringContainsString('"dread" is not one of the trackers of flow "main"', $exception->getMessage());
        }
    }

    #[Test]
    public function markAndClearKeepTheirPropertyOrderAndAnAbsentValue(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys(ReleaseArrays::fixture('valid/v2-dice-pools')));
        /** @var array{sceneTypes: list<array{play: list<array{outcomes: list<array{effects: list<array<string, mixed>>}>}>}>} $canonical */
        $canonical = $content->toArray();
        $outcomes = $canonical['sceneTypes'][0]['play'][0]['outcomes'];

        self::assertSame(ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-dice-pools'))->hash(), $content->hash());
        self::assertSame(['kind' => 'sheet', 'field' => 'physical', 'op' => 'mark', 'value' => 'hurt'], $outcomes[0]['effects'][0]);
        self::assertSame(['kind' => 'sheet', 'field' => 'mental', 'op' => 'clear'], $outcomes[1]['effects'][0]);
        self::assertSame(['kind' => 'sheet', 'field' => 'physical', 'op' => 'clear', 'value' => 'hurt'], $outcomes[1]['effects'][1]);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in valid/v2-dice-pools, its new value and the message
     */
    public static function refusals(): iterable
    {
        $at = 'sceneTypes[0].play[0].outcomes[0].effects[0]';
        $effect = 'sceneTypes.0.play.0.outcomes.0.effects.0';
        $sheet = static fn (string $field, string $op, mixed ...$value): array => ['kind' => 'sheet', 'field' => $field, 'op' => $op, ...$value];

        yield 'mark on a number field' => [$effect, $sheet('agility', 'mark'), $at.'.op: field "agility" is a number field, mark and clear change conditions fields only'];
        yield 'clear on a number field' => [$effect, $sheet('agility', 'clear', ...['value' => 1]), $at.'.op: field "agility" is a number field, mark and clear change conditions fields only'];
        yield 'add on a conditions field' => [$effect, $sheet('physical', 'add', ...['value' => 1]), $at.'.op: field "physical" is a conditions field, add, subtract and set change number and track fields only'];
        yield 'subtract on a conditions field' => [$effect, $sheet('physical', 'subtract', ...['value' => 1]), $at.'.op: field "physical" is a conditions field, add, subtract and set change number and track fields only'];
        yield 'set on a conditions field' => [$effect, $sheet('physical', 'set', ...['value' => 1]), $at.'.op: field "physical" is a conditions field, add, subtract and set change number and track fields only'];
        yield 'an unknown option' => [$effect.'.value', 'ghost', $at.'.value: unknown option "ghost" of conditions field "physical"'];
        yield 'an option of another conditions field' => [$effect.'.value', 'shaken', $at.'.value: unknown option "shaken" of conditions field "physical"'];
        yield 'an option that is not a string' => [$effect.'.value', 5, $at.'.value: must be a string'];
        yield 'an option that is a formula' => [$effect.'.value', ['formula' => '1'], $at.'.value: must be a string'];
        yield 'an option that breaks the key rule' => [$effect.'.value', 'Hurt', $at.'.value: must be 1 to 64 characters'];
        yield 'an unknown operation' => [$effect.'.op', 'toggle', $at.'.op: must be one of "add", "subtract", "set", "mark", "clear"'];
        yield 'mark without a value in an outcome' => [$effect, $sheet('physical', 'mark'), $at.'.value: a mark without an option is allowed only in the cost of a push'];
        yield 'add without a value' => [$effect, $sheet('agility', 'add'), $at.'.value: required'];
        yield 'mark in a step\'s own effects' => ['sceneTypes.0.play.1.effects', [$sheet('physical', 'clear')], 'sceneTypes[0].play[1].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step'];
        yield 'mark in a push with an unknown option' => ['checks.0.push.effects.0', $sheet('physical', 'mark', ...['value' => 'ghost']), 'checks[0].push.effects[0].value: unknown option "ghost" of conditions field "physical"'];
        yield 'mark in a push on a number field' => ['checks.0.push.effects.0', $sheet('agility', 'mark'), 'checks[0].push.effects[0].op: field "agility" is a number field, mark and clear change conditions fields only'];
        yield 'mark in a push on an unknown field' => ['checks.0.push.effects.0', $sheet('ghost', 'mark'), 'checks[0].push.effects[0].field: unknown sheet field "ghost"'];
        yield 'add in a push on a conditions field' => ['checks.0.push.effects.0', $sheet('physical', 'add', ...['value' => 1]), 'checks[0].push.effects[0].op: field "physical" is a conditions field'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aMarkOrClearOutOfPlaceIsRefusedWithItsPath(string $path, mixed $value, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), $path, $value);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aPushCostEffectKeepsTheSheetFormulaScope(): void
    {
        $effect = ['kind' => 'sheet', 'field' => 'agility', 'op' => 'subtract', 'value' => ['formula' => '{physical} + {mental}']];

        /** @var array{checks: list<array{push: array{effects: list<array<string, mixed>>}}>} $canonical */
        $canonical = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'checks.0.push.effects.0', $effect))->toArray();

        self::assertSame($effect, $canonical['checks'][0]['push']['effects'][0]);
    }
}
