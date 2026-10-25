<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Checks;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The checks of schema version 2 (ADR 0024): additive, so releases without checks keep their
 * canonical form and hash; the agreement test covers each rule, this one the canonical form and
 * the rules of pools and pushes (ADR 0041), run against valid/v2-dice-pools: the check "sneak"
 * (a pool sized by a formula, with a push) and "brawl" (a fixed pool and a difficulty).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Checks::class)]
final class ReleaseContentChecksTest extends TestCase
{
    #[Test]
    public function checksKeepTheirAuthoredOrderAndLeaveNullsOut(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-checks');
        $release = ReleaseArrays::with($release, 'checks.1.hint', null);
        $release = ReleaseArrays::with($release, 'checks.1.modifier', null);
        $release = ReleaseArrays::with($release, 'checks.1.difficulty', ['label' => 'Target', 'default' => 5, 'min' => null, 'max' => null]);

        /** @var array{checks: list<array<string, mixed>>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['skill', 'luck'], array_column($content['checks'], 'key'));
        self::assertSame(['key', 'label', 'dice', 'difficulty', 'bands'], array_keys($content['checks'][1]));
        self::assertSame(['label' => 'Target', 'default' => 5], $content['checks'][1]['difficulty']);
        self::assertSame('checks', array_key_last(ReleaseContent::fromArray($release)->toArray()));
    }

    #[Test]
    public function aStepDifficultyAtTheBoundOfTheCheckRangeIsValid(): void
    {
        foreach ([9, 29] as $difficulty) {
            $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-checks'), 'sceneTypes.0.play.0.difficulty', $difficulty);

            /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $content */
            $content = ReleaseContent::fromArray($release)->toArray();

            self::assertSame($difficulty, $content['sceneTypes'][0]['play'][0]['difficulty']);
        }
    }

    #[Test]
    public function aStepDifficultyAboveTheMaxOfTheCheckRangeIsRefused(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-checks'), 'sceneTypes.0.play.0.difficulty', 30);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[0].play[0].difficulty: must be at most 29, the difficulty max of check "skill", 30 given');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function checksAreStillReservedInSchemaVersion1(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-checks');
        $v1 = ['schemaVersion' => 1, 'gameSystem' => $release['gameSystem'], 'oracles' => $release['oracles'], 'flow' => ['steps' => []], 'sheet' => [], 'checks' => $release['checks']];

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks: not supported in schema version 1, must be []');

        ReleaseContent::fromArray($v1);
    }

    #[Test]
    public function poolChecksAndPushesKeepTheirCanonicalForm(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-dice-pools');
        $release = ReleaseArrays::with($release, 'checks.0.dice', null);
        $release = ReleaseArrays::with($release, 'checks.1.pool', ['successAt' => 8, 'sides' => 10, 'count' => 3]);
        $release = ReleaseArrays::with($release, 'checks.1.push', null);

        /** @var array{checks: list<array<string, mixed>>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['key', 'label', 'hint', 'pool', 'push', 'bands'], array_keys($content['checks'][0]));
        self::assertSame(['count' => ['formula' => '{agility} + {stealth} - {physical}'], 'sides' => 6, 'successAt' => 6], $content['checks'][0]['pool']);
        self::assertSame(['count' => 3, 'sides' => 10, 'successAt' => 8], $content['checks'][1]['pool']);
        self::assertSame(['key', 'label', 'pool', 'difficulty', 'bands'], array_keys($content['checks'][1]));
        self::assertSame(ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-dice-pools'))->hash(), ReleaseContent::fromArray($release)->hash());
    }

    #[Test]
    public function aPushWithoutEffectsIsValidAndKeepsNoEffectsKey(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'checks.0.push', ['label' => 'Pay the price', 'effects' => null]);

        /** @var array{checks: list<array{push: array<string, mixed>}>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['label' => 'Pay the price'], $content['checks'][0]['push']);
    }

    #[Test]
    public function aPushCostMayMarkAWithoutAnOptionAndAPoolSizeMayReadConditions(): void
    {
        /** @var array{checks: list<array{push: array{effects: list<array<string, mixed>>}}>} $content */
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-dice-pools'))->toArray();

        self::assertSame(['kind' => 'sheet', 'field' => 'physical', 'op' => 'mark'], $content['checks'][0]['push']['effects'][0]);
        self::assertSame(['kind' => 'tracker', 'tracker' => 'dread', 'op' => 'add', 'value' => 1], $content['checks'][0]['push']['effects'][1]);
    }

    /**
     * @return iterable<string, array{\Closure(array<mixed>): array<mixed>, string}> how to break valid/v2-dice-pools and the message
     */
    public static function refusals(): iterable
    {
        $set = static fn (string $path, mixed $value): \Closure => static fn (array $release): array => ReleaseArrays::with($release, $path, $value);
        // The second check of the fixture, rolling a dice expression, to put a push on.
        $dice = static fn (array $release): array => ReleaseArrays::with(ReleaseArrays::with($release, 'checks.1.pool', null), 'checks.1.dice', '1d6');

        yield 'both dice and a pool' => [$set('checks.0.dice', '1d6'), 'checks[0]: give either dice or pool, not both'];
        yield 'neither dice nor a pool' => [static fn (array $release): array => ReleaseArrays::without($release, 'checks.0.pool'), 'checks[0].dice: required unless the check declares a pool'];
        yield 'a modifier with a pool' => [$set('checks.0.modifier', '{agility}'), 'checks[0].modifier: a pool check has no modifier, its count formula sizes the pool'];
        yield 'a pool that is not an object' => [$set('checks.0.pool', 6), 'checks[0].pool: must be an object'];
        yield 'a pool with an unknown property' => [$set('checks.0.pool', ['count' => 1, 'sides' => 6, 'successAt' => 6, 'hunger' => 1]), 'checks[0].pool.hunger: unknown property'];
        yield 'a pool without a count' => [$set('checks.0.pool', ['sides' => 6, 'successAt' => 6]), 'checks[0].pool.count: required'];
        yield 'a pool without sides' => [$set('checks.0.pool', ['count' => 1, 'successAt' => 6]), 'checks[0].pool.sides: required'];
        yield 'a pool without successAt' => [$set('checks.0.pool', ['count' => 1, 'sides' => 6]), 'checks[0].pool.successAt: required'];
        yield 'a count of zero' => [$set('checks.0.pool.count', 0), 'checks[0].pool.count: must be at least 1, 0 given'];
        yield 'a count of 101' => [$set('checks.0.pool.count', 101), 'checks[0].pool.count: must be at most 100, 101 given'];
        yield 'a count that is a string' => [$set('checks.0.pool.count', '3'), 'checks[0].pool.count: must be an integer or {formula: string}'];
        yield 'a count that is a list' => [$set('checks.0.pool.count', [3]), 'checks[0].pool.count: must be an integer or {formula: string}'];
        yield 'an empty count object' => [$set('checks.0.pool.count', []), 'checks[0].pool.count: must be an integer or {formula: string}'];
        yield 'a count with another property' => [$set('checks.0.pool.count', ['formula' => '3', 'min' => 1]), 'checks[0].pool.count.min: unknown property'];
        yield 'a count formula that is blank' => [$set('checks.0.pool.count.formula', ''), 'checks[0].pool.count.formula: '];
        yield 'a count formula over 200 characters' => [$set('checks.0.pool.count.formula', str_repeat('1+', 100).'1'), 'checks[0].pool.count.formula: must be at most 200 characters'];
        yield 'a count formula that does not parse' => [$set('checks.0.pool.count.formula', '{agility} +'), 'checks[0].pool.count.formula: '];
        yield 'a count formula reading an unknown field' => [$set('checks.0.pool.count.formula', '{ghost}'), 'checks[0].pool.count.formula: {ghost}: unknown field "ghost"'];
        yield 'a count formula reading an unknown tracker' => [$set('checks.0.pool.count.formula', '{tracker:ghost}'), 'checks[0].pool.count.formula: {tracker:ghost}: unknown tracker "ghost"'];
        yield 'a count formula reading the difficulty' => [$set('checks.1.pool.count', ['formula' => '{check:difficulty}']), 'checks[1].pool.count.formula: {check:difficulty}: a check reference is read only by the bands of a check'];
        yield 'sides of one' => [$set('checks.0.pool.sides', 1), 'checks[0].pool.sides: must be at least 2, 1 given'];
        yield 'sides of 1001' => [$set('checks.0.pool.sides', 1001), 'checks[0].pool.sides: must be at most 1000, 1001 given'];
        yield 'sides that are a string' => [$set('checks.0.pool.sides', 'd6'), 'checks[0].pool.sides: must be an integer'];
        yield 'successAt of zero' => [$set('checks.0.pool.successAt', 0), 'checks[0].pool.successAt: must be at least 1, 0 given'];
        yield 'successAt above sides' => [$set('checks.0.pool.successAt', 7), 'checks[0].pool.successAt: must be at most sides (6), 7 given'];
        yield 'successAt that is a float' => [$set('checks.0.pool.successAt', 5.5), 'checks[0].pool.successAt: must be an integer'];
        yield 'a push without a pool' => [static fn (array $release): array => ReleaseArrays::with($dice($release), 'checks.1.push', ['label' => 'Push']), 'checks[1].push: only a check with a pool can be pushed'];
        yield 'a push that is not an object' => [$set('checks.0.push', 'yes'), 'checks[0].push: must be an object'];
        yield 'a push without a label' => [$set('checks.0.push', ['effects' => []]), 'checks[0].push.label: required'];
        yield 'a blank push label' => [$set('checks.0.push.label', ' '), 'checks[0].push.label: must not be blank'];
        yield 'a push label over 100 characters' => [$set('checks.0.push.label', str_repeat('a', 101)), 'checks[0].push.label: must be at most 100 characters'];
        yield 'a push with an unknown property' => [$set('checks.0.push', ['label' => 'Push', 'times' => 2]), 'checks[0].push.times: unknown property'];
        yield 'push effects that are not a list' => [$set('checks.0.push.effects', 'none'), 'checks[0].push.effects: must be a list'];
        yield 'more than 10 push effects' => [$set('checks.0.push.effects', array_fill(0, 11, ['kind' => 'sheet', 'field' => 'physical', 'op' => 'clear'])), 'checks[0].push.effects: at most 10 effects, 11 given'];
        yield 'a push effect of another kind' => [$set('checks.0.push.effects', [['kind' => 'endPhase']]), 'checks[0].push.effects[0].kind: a push cost allows sheet and tracker effects only, "endPhase" given'];
        yield 'a push effect creating an NPC' => [$set('checks.0.push.effects', [['kind' => 'createNpc']]), 'checks[0].push.effects[0].kind: a push cost allows sheet and tracker effects only, "createNpc" given'];
        yield 'a push tracker effect on an unknown tracker' => [$set('checks.0.push.effects.1.tracker', 'ghost'), 'checks[0].push.effects[1].tracker: unknown tracker "ghost"'];
    }

    /**
     * @param \Closure(array<mixed>): array<mixed> $break
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aMalformedPoolOrPushIsRefusedWithItsPath(\Closure $break, string $message): void
    {
        $release = $break(ReleaseArrays::fixture('valid/v2-dice-pools'));

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aSheetEffectInAPushCostIsCheckedInTheSheetScope(): void
    {
        $effects = [['kind' => 'sheet', 'field' => 'agility', 'op' => 'subtract', 'value' => ['formula' => '{check:difficulty}']]];
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'checks.0.push.effects', $effects);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks[0].push.effects[0].value.formula: {check:difficulty}: a check reference is read only by the bands of a check');

        ReleaseContent::fromArray($release);
    }
}
