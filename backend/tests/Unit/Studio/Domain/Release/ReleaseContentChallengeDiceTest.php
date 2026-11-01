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
 * The parts of ADR 0049, run against valid/v2-challenge-dice: the check "edge" is an action roll
 * (1d6 plus a modifier against two d10, with a momentum field and a burn) and "fulfill" a progress
 * roll (a modifier alone). All is additive, so releases without it keep their hash.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Checks::class)]
final class ReleaseContentChallengeDiceTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-challenge-dice';

    /**
     * @return iterable<string, array{string, string}> fixtures without a challenge and their hash before ADR 0049
     */
    public static function hashesBefore(): iterable
    {
        yield 'dice pools' => ['valid/v2-dice-pools', 'af1c061a62044529320faebc8208072daddd8092ba1a8db9b85233c67dc4e954'];
        yield 'credits' => ['valid/v2-credits', 'cc97c0f26d1485732151bc23f359ce6e208f47f7bdb34c91a2850c9ebcffef00'];
        yield 'flow choices' => ['valid/v2-flow-choices', '38d8c044668b2d02a0a2049ba860a2145f2bce71aa122a6ac364ed76689f6439'];
    }

    #[Test]
    #[DataProvider('hashesBefore')]
    public function aReleaseWithoutAChallengeKeepsItsHash(string $fixture, string $hash): void
    {
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->hash());
    }

    #[Test]
    public function aNullChallengeIsLeftOutOfTheCanonicalForm(): void
    {
        $plain = ReleaseArrays::fixture('valid/v2-checks');
        $withNull = ReleaseArrays::with($plain, 'checks.0.challenge', null);

        self::assertSame(ReleaseContent::fromArray($plain)->hash(), ReleaseContent::fromArray($withNull)->hash());
    }

    #[Test]
    public function theActionAndProgressRollsKeepTheirCanonicalForm(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.0.challenge.scoreMax', null);
        $release = ReleaseArrays::with($release, 'checks.1.dice', null);
        $release = ReleaseArrays::with($release, 'checks.1.pool', null);

        /** @var array{checks: list<array<string, mixed>>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['count' => 2, 'sides' => 10, 'momentum' => 'momentum'], $content['checks'][0]['challenge']);
        self::assertSame('1d6', $content['checks'][0]['dice']);
        self::assertSame(['label' => 'Burn momentum', 'effects' => [['kind' => 'sheet', 'field' => 'momentum', 'op' => 'set', 'value' => ['formula' => '{momentum-reset}']]]], $content['checks'][0]['push'] ?? null);
        self::assertSame(['count' => 2, 'sides' => 10], $content['checks'][1]['challenge']);
        self::assertArrayNotHasKey('dice', $content['checks'][1]);
        self::assertArrayNotHasKey('pool', $content['checks'][1]);
    }

    #[Test]
    public function aChallengeOfTheFullRangeIsValid(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.1.challenge', ['count' => 10, 'sides' => 1000, 'scoreMax' => 1000]);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
    }

    #[Test]
    public function aSingleDieOfAnySidesIsAnActionDie(): void
    {
        foreach (['1d6', 'd8', ' 1D10 ', 'd%'] as $dice) {
            $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.0.dice', $dice);

            self::assertNotSame('', ReleaseContent::fromArray($release)->hash(), $dice);
        }
    }

    #[Test]
    public function aMultiDieActionRollWithoutMomentumOrPushIsValid(): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = ReleaseArrays::with($release, 'checks.0.dice', '2d6');
        $release = ReleaseArrays::with($release, 'checks.0.challenge.momentum', null);
        $release = ReleaseArrays::with($release, 'checks.0.push', null);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in valid/v2-challenge-dice, its new value and the message
     */
    public static function refusals(): iterable
    {
        yield 'a challenge that is not an object' => ['checks.0.challenge', 2, 'checks[0].challenge: must be an object'];
        yield 'a challenge without a count' => ['checks.0.challenge.count', null, 'checks[0].challenge.count: required'];
        yield 'a challenge with an unknown property' => ['checks.0.challenge.face', 10, 'checks[0].challenge.face: unknown property'];
        yield 'one challenge die' => ['checks.0.challenge.count', 1, 'checks[0].challenge.count: must be at least 2, 1 given'];
        yield 'eleven challenge dice' => ['checks.0.challenge.count', 11, 'checks[0].challenge.count: must be at most 10, 11 given'];
        yield 'a count that is a string' => ['checks.0.challenge.count', '2', 'checks[0].challenge.count: must be an integer'];
        yield 'a count that is fractional' => ['checks.0.challenge.count', 2.5, 'checks[0].challenge.count: must be an integer'];
        yield 'one side' => ['checks.0.challenge.sides', 1, 'checks[0].challenge.sides: must be at least 2, 1 given'];
        yield '1001 sides' => ['checks.0.challenge.sides', 1001, 'checks[0].challenge.sides: must be at most 1000, 1001 given'];
        yield 'sides that are a string' => ['checks.0.challenge.sides', '10', 'checks[0].challenge.sides: must be an integer'];
        yield 'a score cap of zero' => ['checks.0.challenge.scoreMax', 0, 'checks[0].challenge.scoreMax: must be at least 1, 0 given'];
        yield 'a score cap of 1001' => ['checks.0.challenge.scoreMax', 1001, 'checks[0].challenge.scoreMax: must be at most 1000, 1001 given'];
        yield 'a score cap that is a string' => ['checks.0.challenge.scoreMax', '10', 'checks[0].challenge.scoreMax: must be an integer'];
        yield 'a momentum that is not a string' => ['checks.0.challenge.momentum', 5, 'checks[0].challenge.momentum: must be a string'];
        yield 'a momentum that is not a key' => ['checks.0.challenge.momentum', 'Not A Key', 'checks[0].challenge.momentum: must be 1 to 64 characters'];
        yield 'a momentum naming no field' => ['checks.0.challenge.momentum', 'ghost', 'checks[0].challenge.momentum: unknown sheet field "ghost"'];
        yield 'a momentum naming a derived field' => ['checks.0.challenge.momentum', 'momentum-reset', 'checks[0].challenge.momentum: field "momentum-reset" is a derived field, momentum must be a number field'];
        yield 'a pool beside a challenge' => ['checks.0.pool', ['count' => 3, 'sides' => 10, 'successAt' => 6], 'checks[0].challenge: a challenge check has no pool'];
        yield 'a difficulty on an action roll' => ['checks.0.difficulty', ['label' => 'DV', 'default' => 3], 'checks[0].difficulty: a challenge check has no difficulty'];
        yield 'a difficulty on a progress roll' => ['checks.1.difficulty', ['label' => 'DV', 'default' => 3], 'checks[1].difficulty: a challenge check has no difficulty'];
        yield 'a progress roll without a modifier' => ['checks.1.modifier', null, 'checks[1].modifier: required, it is the score of a progress roll'];
        yield 'a progress roll with a momentum' => ['checks.1.challenge.momentum', 'momentum', 'checks[1].challenge.momentum: a progress roll ignores momentum'];
        yield 'a momentum with two action dice' => ['checks.0.dice', '2d6', 'checks[0].challenge.momentum: needs dice that rolls exactly one die, "2d6" given'];
        yield 'a momentum with a selector' => ['checks.0.dice', '1d6kh1', 'checks[0].challenge.momentum: needs dice that rolls exactly one die, "1d6kh1" given'];
        yield 'a momentum with arithmetic' => ['checks.0.dice', '1d6+1', 'checks[0].challenge.momentum: needs dice that rolls exactly one die, "1d6+1" given'];
        yield 'a momentum with a constant' => ['checks.0.dice', '4', 'checks[0].challenge.momentum: needs dice that rolls exactly one die, "4" given'];
        yield 'a burn without a momentum' => ['checks.0.challenge.momentum', null, 'checks[0].push: burning momentum needs challenge.momentum'];
        yield 'a burn with a push cap' => ['checks.0.push.maxDice', 2, 'checks[0].push.maxDice: a burn re-rolls no dice'];
        yield 'a push on a progress roll' => ['checks.1.push', ['label' => 'Burn'], 'checks[1].push: burning momentum needs challenge.momentum'];
        yield 'a burn effect on an unknown field' => ['checks.0.push.effects.0.field', 'ghost', 'checks[0].push.effects[0].field: unknown sheet field "ghost"'];
        yield 'a special outcome on an action roll' => ['sceneTypes.0.play.0.outcomes.0', ['special' => 'critical'], 'sceneTypes[0].play[0].outcomes[0].special: check "edge" never has a critical, it needs pool.criticals'];
        yield 'a special outcome on a progress roll' => ['sceneTypes.0.play.1.outcomes', [['special' => 'bestialFailure']], 'sceneTypes[0].play[1].outcomes[0].special: check "fulfill" never has a bestialFailure, it needs pool.hunger'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function itRefusesAMalformedChallengeWithItsPath(string $path, mixed $value, string $message): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = null === $value ? ReleaseArrays::without($release, $path) : ReleaseArrays::with($release, $path, $value);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aPushStaysRefusedOnAPlainDiceCheck(): void
    {
        $release = ReleaseArrays::without(ReleaseArrays::fixture(self::FIXTURE), 'checks.0.challenge');
        $release = ReleaseArrays::without($release, 'sceneTypes.0.play.0.outcomes');

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks[0].push: only a check with a pool can be pushed');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aPoolCheckStillRefusesAModifier(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-hunger-dice');

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks[0].modifier: a pool check has no modifier');

        ReleaseContent::fromArray(ReleaseArrays::with($release, 'checks.0.modifier', '{charisma}'));
    }
}
