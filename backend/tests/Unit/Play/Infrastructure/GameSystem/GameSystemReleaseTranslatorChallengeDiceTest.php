<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
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
 * Play's anti-corruption layer plays challenge dice and the burn of their momentum, which is a challenge check's push
 * (ADR 0049). Run against valid/v2-challenge-dice.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorChallengeDiceTest extends TestCase
{
    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    /**
     * valid/v2-challenge-dice without the burn.
     *
     * @return array<mixed>
     */
    private function playable(): array
    {
        return ReleaseArrays::without(ReleaseViews::fixtureContent('valid/v2-challenge-dice'), 'checks.0.push');
    }

    #[Test]
    public function anActionRollAndAProgressRollAreTranslated(): void
    {
        $snapshot = $this->translate($this->playable());

        $action = $snapshot->check('edge');
        self::assertInstanceOf(Check::class, $action);
        self::assertEquals(new CheckChallenge(2, 10, 10, 'momentum'), $action->challenge);
        self::assertSame('1d6', $action->dice?->notation());
        self::assertNull($action->push);
        $progress = $snapshot->check('fulfill');
        self::assertInstanceOf(Check::class, $progress);
        self::assertEquals(new CheckChallenge(2, 10), $progress->challenge);
        self::assertNull($progress->dice);
        self::assertNotNull($progress->modifier);
    }

    #[Test]
    public function aMultiDieActionRollWithoutMomentumIsTranslated(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.0.dice', '2d6');
        $content = ReleaseArrays::with($content, 'checks.0.challenge', ['count' => 3, 'sides' => 6]);

        self::assertSame('2d6', $this->translate($content)->check('edge')?->dice?->notation());
    }

    #[Test]
    public function theBurnOfAChallengeCheckIsTranslatedAsItsPush(): void
    {
        $edge = $this->translate(ReleaseViews::fixtureContent('valid/v2-challenge-dice'))->check('edge');

        self::assertInstanceOf(Check::class, $edge);
        self::assertSame('Burn momentum', $edge->push?->label);
        self::assertNull($edge->push->maxDice);
        self::assertCount(1, $edge->push->effects);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the fixture with its burn, its value and the refusal
     */
    public static function refusedBurns(): iterable
    {
        yield 'a burn on a check naming no momentum' => ['checks.0.challenge.momentum', null, 'checks[0].push: a challenge push burns momentum'];
        yield 'a burn with a maxDice' => ['checks.0.push.maxDice', 2, 'checks[0].push.maxDice'];
        yield 'a burn on a progress roll' => ['checks.1.push', ['label' => 'Burn'], 'checks[1].push: a challenge push burns momentum'];
        yield 'a burn whose cost reads a ghost field' => ['checks.0.push.effects.0.field', 'ghost', 'checks[0].push.effects[0]'];
        yield 'a burn cost that is not an object' => ['checks.0.push', 'burn', 'checks[0].push'];
    }

    #[Test]
    #[DataProvider('refusedBurns')]
    public function itFailsClosedOnABurnThatBreaksTheShapeWithItsPath(string $path, mixed $value, string $at): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($at);

        $this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-challenge-dice'), $path, $value));
    }

    #[Test]
    public function aNullPushReadsAsNoPush(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.0.push', null);

        $check = $this->translate($content)->check('edge');

        self::assertInstanceOf(Check::class, $check);
        self::assertNotNull($check->challenge);
        self::assertNull($check->push);
    }

    #[Test]
    public function aNullChallengeReadsAsNoChallenge(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.0.challenge', null);
        $check = $this->translate($content)->check('edge');

        self::assertInstanceOf(Check::class, $check);
        self::assertNull($check->challenge);
        self::assertSame('1d6', $check->dice?->notation());
    }

    #[Test]
    public function aPushWithoutChallengeDiceOrAPoolIsStillRefused(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.0.push', ['label' => 'Burn momentum']);
        $content = ReleaseArrays::without($content, 'checks.0.challenge');

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('checks[0].push');

        $this->translate($content);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function malformedParts(): iterable
    {
        yield 'a challenge that is not an object' => ['checks.1.challenge', 2, 'checks[1].challenge'];
        yield 'a null challenge on a check with no dice' => ['checks.1.challenge', null, 'checks[1].dice'];
        yield 'an unknown property' => ['checks.1.challenge.face', 10, 'checks[1].challenge.face'];
        yield 'no count' => ['checks.1.challenge.count', null, 'checks[1].challenge.count'];
        yield 'one die' => ['checks.1.challenge.count', 1, 'checks[1].challenge.count'];
        yield 'eleven dice' => ['checks.1.challenge.count', 11, 'checks[1].challenge.count'];
        yield 'a count that is a string' => ['checks.1.challenge.count', '2', 'checks[1].challenge.count'];
        yield 'one side' => ['checks.1.challenge.sides', 1, 'checks[1].challenge.sides'];
        yield '1001 sides' => ['checks.1.challenge.sides', 1001, 'checks[1].challenge.sides'];
        yield 'a cap of zero' => ['checks.1.challenge.scoreMax', 0, 'checks[1].challenge.scoreMax'];
        yield 'a cap of 1001' => ['checks.1.challenge.scoreMax', 1001, 'checks[1].challenge.scoreMax'];
        yield 'a momentum that is not a string' => ['checks.0.challenge.momentum', 5, 'checks[0].challenge.momentum'];
        yield 'a momentum naming no field' => ['checks.0.challenge.momentum', 'ghost', 'checks[0].challenge.momentum'];
        yield 'a momentum naming a derived field' => ['checks.0.challenge.momentum', 'momentum-reset', 'checks[0].challenge.momentum'];
        yield 'a momentum on a progress roll' => ['checks.1.challenge.momentum', 'momentum', 'checks[1].challenge.momentum'];
        yield 'a momentum on two dice' => ['checks.0.dice', '2d6', 'checks[0].challenge.momentum'];
        yield 'a momentum on a die and a number' => ['checks.0.dice', '1d6+1', 'checks[0].challenge.momentum'];
        yield 'a progress roll without a modifier' => ['checks.1.modifier', null, 'checks[1].modifier'];
        yield 'a difficulty' => ['checks.0.difficulty', ['label' => 'Rank', 'default' => 2], 'checks[0].difficulty'];
        yield 'a pool' => ['checks.0.pool', ['count' => 2, 'sides' => 6, 'successAt' => 5], 'checks[0].pool'];
        yield 'dice that are not notation' => ['checks.0.dice', 'banana', 'checks[0].dice'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function itFailsClosedOnAMalformedPartWithItsPath(string $path, mixed $value, string $at): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($at);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }

    #[Test]
    public function aStepCannotNameAMatchOutcome(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'sceneTypes.0.play.0.outcomes.2', ['special' => 'match', 'effects' => []]);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('outcomes[2].special');

        $this->translate($content);
    }

    #[Test]
    public function aMatchKeepsTheOutcomeOfTheBand(): void
    {
        $strike = $this->translate($this->playable())->sceneType('journey')?->play->step('strike');

        self::assertInstanceOf(CheckStep::class, $strike);
        self::assertSame($strike->outcomes['strong'], $strike->outcomeFor('strong', SpecialResult::Match));
        self::assertSame($strike->outcomes['miss'], $strike->outcomeFor('miss', SpecialResult::Match));
    }
}
