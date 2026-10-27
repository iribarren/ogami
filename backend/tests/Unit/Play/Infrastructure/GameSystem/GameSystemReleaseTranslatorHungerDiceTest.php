<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer plays critical pairs, Hunger dice, a push cap and check step outcomes
 * on a special result (ADR 0044), and refuses the credits badge until it plays it (the next commit of
 * preset-vtm-chronicle's slice 3). Run against valid/v2-hunger-dice.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorHungerDiceTest extends TestCase
{
    /**
     * valid/v2-hunger-dice with the credits badge, not played yet, left out.
     *
     * @return array<mixed>
     */
    private function playable(): array
    {
        $content = ReleaseViews::fixtureContent('valid/v2-hunger-dice');

        return ReleaseArrays::without($content, 'gameSystem.credits.badge');
    }

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    #[Test]
    public function theReleaseWithoutThemIsPlayable(): void
    {
        $snapshot = $this->translate($this->playable());

        self::assertNotNull($snapshot->check('persuade'));
        self::assertNotNull($snapshot->credits());
    }

    #[Test]
    public function itReadsCriticalPairsHungerDiceAndAPushCap(): void
    {
        $snapshot = $this->translate($this->playable());

        $persuade = $snapshot->check('persuade');
        $frenzy = $snapshot->check('frenzy');
        $aim = $snapshot->check('aim');
        self::assertInstanceOf(Check::class, $persuade);
        self::assertInstanceOf(Check::class, $frenzy);
        self::assertInstanceOf(Check::class, $aim);
        self::assertInstanceOf(CheckPool::class, $persuade->pool);
        self::assertInstanceOf(CheckPool::class, $frenzy->pool);
        self::assertInstanceOf(CheckPool::class, $aim->pool);
        self::assertSame(2, $persuade->pool->criticals);
        self::assertSame('{hunger}', $persuade->pool->hunger instanceof Formula ? $persuade->pool->hunger->toString() : null);
        self::assertSame(3, $persuade->push?->maxDice);
        self::assertSame(1, $frenzy->pool->hunger);
        self::assertNull($frenzy->pool->criticals);
        self::assertNull($aim->push);
        self::assertNull($aim->pool->hunger);
    }

    #[Test]
    public function aNullCriticalsHungerOrCapIsAbsent(): void
    {
        // Without the pool parts, the step can no longer branch on the special results they enable.
        $content = ReleaseArrays::with($this->playable(), 'sceneTypes.0.play.0.outcomes', [['band' => 'fail']]);
        $content = ReleaseArrays::with($content, 'checks.0.pool.criticals', null);
        $content = ReleaseArrays::with($content, 'checks.0.pool.hunger', null);
        $content = ReleaseArrays::with($content, 'checks.0.push.maxDice', null);

        $persuade = $this->translate($content)->check('persuade');

        self::assertInstanceOf(Check::class, $persuade);
        self::assertInstanceOf(CheckPool::class, $persuade->pool);
        self::assertNull($persuade->pool->criticals);
        self::assertNull($persuade->pool->hunger);
        self::assertNull($persuade->push?->maxDice);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function malformedParts(): iterable
    {
        yield 'criticals that are not an object' => ['checks.0.pool.criticals', 2, 'checks[0].pool.criticals'];
        yield 'criticals without a bonus' => ['checks.0.pool.criticals', [], 'checks[0].pool.criticals.bonus'];
        yield 'criticals with an unknown property' => ['checks.0.pool.criticals', ['bonus' => 2, 'face' => 10], 'checks[0].pool.criticals.face'];
        yield 'a criticals bonus of zero' => ['checks.0.pool.criticals.bonus', 0, 'checks[0].pool.criticals.bonus'];
        yield 'a criticals bonus of 11' => ['checks.0.pool.criticals.bonus', 11, 'checks[0].pool.criticals.bonus'];
        yield 'Hunger that is an integer' => ['checks.0.pool.hunger', 2, 'checks[0].pool.hunger'];
        yield 'Hunger without a count' => ['checks.0.pool.hunger', [], 'checks[0].pool.hunger.count'];
        yield 'Hunger with an unknown property' => ['checks.0.pool.hunger', ['count' => 1, 'colour' => 'red'], 'checks[0].pool.hunger.colour'];
        yield 'a negative Hunger count' => ['checks.1.pool.hunger.count', -1, 'checks[1].pool.hunger.count'];
        yield 'a Hunger count of 101' => ['checks.1.pool.hunger.count', 101, 'checks[1].pool.hunger.count'];
        yield 'a Hunger count that is a string' => ['checks.1.pool.hunger.count', '2', 'checks[1].pool.hunger.count'];
        yield 'a Hunger formula reading an unknown field' => ['checks.0.pool.hunger.count.formula', '{ghost}', 'checks[0].pool.hunger.count.formula'];
        yield 'a push cap of zero' => ['checks.0.push.maxDice', 0, 'checks[0].push.maxDice'];
        yield 'a push cap of 101' => ['checks.0.push.maxDice', 101, 'checks[0].push.maxDice'];
        yield 'a push cap that is a string' => ['checks.0.push.maxDice', '3', 'checks[0].push.maxDice'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function itFailsClosedOnAMalformedPartWithItsPath(string $path, mixed $value, string $at): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($at);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function unplayedParts(): iterable
    {
        yield 'a credits badge' => ['gameSystem.credits.badge', 'dark-pack', 'gameSystem.credits.badge: a credits badge is not played yet (ADR 0044).'];
    }

    #[Test]
    #[DataProvider('unplayedParts')]
    public function itRefusesAPartItDoesNotPlayYet(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }

    #[Test]
    public function itReadsTheOutcomesOnASpecialResultAheadOfTheBandOnes(): void
    {
        $feed = $this->translate($this->playable())->sceneType('hunt')?->play->step('feed');

        self::assertInstanceOf(CheckStep::class, $feed);
        self::assertSame(['fail'], array_keys($feed->outcomes));
        self::assertSame(['bestialFailure', 'messyCritical', 'critical'], array_keys($feed->specialOutcomes));
        self::assertSame('compulsion', $feed->specialOutcomes['bestialFailure']->next);
        self::assertCount(1, $feed->specialOutcomes['messyCritical']->effects);
        self::assertSame($feed->specialOutcomes['bestialFailure'], $feed->outcomeFor('fail', SpecialResult::BestialFailure));
        self::assertSame($feed->outcomes['fail'], $feed->outcomeFor('fail'));
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function malformedSpecialOutcomes(): iterable
    {
        yield 'an unknown special' => ['sceneTypes.0.play.0.outcomes.1.special', 'frenzy', 'sceneTypes[0].play[0].outcomes[1].special: unknown special result "frenzy"'];
        yield 'a special that is not a string' => ['sceneTypes.0.play.0.outcomes.1.special', 3, 'sceneTypes[0].play[0].outcomes[1].special'];
        yield 'a special twice' => ['sceneTypes.0.play.0.outcomes.2.special', 'bestialFailure', 'sceneTypes[0].play[0].outcomes[2].special: duplicate outcome special "bestialFailure".'];
        yield 'a band and a special in one entry' => ['sceneTypes.0.play.0.outcomes.1.band', 'win', 'sceneTypes[0].play[0].outcomes[1]: must name exactly one of band and special.'];
        yield 'a band twice' => ['sceneTypes.0.play.0.outcomes.1', ['band' => 'fail'], 'sceneTypes[0].play[0].outcomes[1].band: duplicate outcome band "fail".'];
        yield 'an entry naming neither' => ['sceneTypes.0.play.0.outcomes.1', ['next' => 'compulsion'], 'sceneTypes[0].play[0].outcomes[1]: must name exactly one of band and special.'];
        yield 'a critical of a check without critical pairs' => ['sceneTypes.0.play.1.outcomes.0.special', 'critical', 'sceneTypes[0].play[1].outcomes[0].special: check "frenzy" cannot produce the special result "critical"'];
        yield 'a bestial failure of a check without Hunger dice' => ['sceneTypes.0.play.0.check', 'aim', 'sceneTypes[0].play[0].outcomes[1].special: check "aim" cannot produce the special result "bestialFailure"'];
    }

    #[Test]
    #[DataProvider('malformedSpecialOutcomes')]
    public function itFailsClosedOnAMalformedSpecialOutcomeWithItsPath(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }

    #[Test]
    public function aLiteralHungerCountOfZeroCannotProduceTheHungerResults(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.1.pool.hunger.count', 0);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('check "frenzy" cannot produce the special result "bestialFailure"');

        $this->translate($content);
    }

    #[Test]
    public function everyCheckOfASetMustBeAbleToProduceTheSpecialResult(): void
    {
        $content = ReleaseArrays::without($this->playable(), 'sceneTypes.0.play.0.check');
        $content = ReleaseArrays::with($content, 'sceneTypes.0.play.0.checks', ['persuade', 'aim']);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('check "aim" cannot produce the special result "bestialFailure"');

        $this->translate($content);
    }
}
