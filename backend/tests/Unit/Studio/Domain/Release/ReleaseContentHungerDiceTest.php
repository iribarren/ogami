<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Checks;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of ADR 0044, run against valid/v2-hunger-dice: critical pairs and Hunger dice on a
 * pool, a push capped at maxDice, check step outcomes on a special result and the credits badge.
 * The check "persuade" has all of them, "frenzy" Hunger dice only and "aim" critical pairs only.
 * All are additive, so releases without them keep their hash.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Checks::class)]
#[CoversClass(Steps::class)]
#[CoversClass(ReleaseVersion2::class)]
final class ReleaseContentHungerDiceTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-hunger-dice';

    /**
     * @return iterable<string, array{string, string}> fixtures without the new parts and their hash before ADR 0044
     */
    public static function hashesBefore(): iterable
    {
        yield 'dice pools' => ['valid/v2-dice-pools', 'af1c061a62044529320faebc8208072daddd8092ba1a8db9b85233c67dc4e954'];
        yield 'credits' => ['valid/v2-credits', 'cc97c0f26d1485732151bc23f359ce6e208f47f7bdb34c91a2850c9ebcffef00'];
        yield 'flow choices' => ['valid/v2-flow-choices', '38d8c044668b2d02a0a2049ba860a2145f2bce71aa122a6ac364ed76689f6439'];
    }

    #[Test]
    #[DataProvider('hashesBefore')]
    public function aReleaseWithoutTheNewPartsKeepsItsHash(string $fixture, string $hash): void
    {
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->hash());
    }

    #[Test]
    public function theNewPartsKeepTheirCanonicalFormAndLeaveNullsOut(): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = ReleaseArrays::with($release, 'checks.1.pool.criticals', null);
        $release = ReleaseArrays::with($release, 'checks.2.pool.hunger', null);
        $release = ReleaseArrays::with($release, 'checks.2.push', null);
        $release = ReleaseArrays::with($release, 'gameSystem.credits.badge', null);
        self::assertSame(ReleaseContent::fromArray(ReleaseArrays::without(ReleaseArrays::fixture(self::FIXTURE), 'gameSystem.credits.badge'))->hash(), ReleaseContent::fromArray($release)->hash());

        $release = ReleaseArrays::with($release, 'checks.2.push', ['label' => 'Spend Willpower', 'maxDice' => null]);
        $release = ReleaseArrays::with($release, 'gameSystem.credits', ['notice' => 'Unofficial fan content.', 'license' => null, 'badge' => 'dark-pack']);

        /** @var array{gameSystem: array{credits: array<string, mixed>}, checks: list<array<string, mixed>>, sceneTypes: list<array{play: list<array{outcomes: list<array<string, mixed>>}>}>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['count' => ['formula' => '{charisma} + {persuasion}'], 'sides' => 10, 'successAt' => 6, 'criticals' => ['bonus' => 2], 'hunger' => ['count' => ['formula' => '{hunger}']]], $content['checks'][0]['pool']);
        self::assertSame(['label' => 'Spend Willpower', 'effects' => [['kind' => 'sheet', 'field' => 'willpower', 'op' => 'subtract', 'value' => 1]], 'maxDice' => 3], $content['checks'][0]['push']);
        self::assertSame(['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => ['count' => 1]], $content['checks'][1]['pool']);
        self::assertSame(['label' => 'Spend Willpower'], $content['checks'][2]['push']);
        self::assertSame(['notice' => 'Unofficial fan content.', 'badge' => 'dark-pack'], $content['gameSystem']['credits']);
        self::assertSame(
            [['band' => 'fail'], ['special' => 'bestialFailure'], ['special' => 'messyCritical'], ['special' => 'critical']],
            array_map(static fn (array $outcome): array => array_intersect_key($outcome, ['band' => true, 'special' => true]), $content['sceneTypes'][0]['play'][0]['outcomes']),
        );
    }

    #[Test]
    public function aLiteralHungerCountOfZeroIsValid(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.1.pool.hunger.count', 0);
        $release = ReleaseArrays::with($release, 'sceneTypes.0.play.1.outcomes', []);

        /** @var array{checks: list<array{pool: array<string, mixed>}>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['count' => 0], $content['checks'][1]['pool']['hunger']);
    }

    #[Test]
    public function aLiteralHungerCountOfZeroRefusesTheOutcomesNeedingHungerDice(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.1.pool.hunger.count', 0);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[0].play[1].outcomes[0].special: check "frenzy" never has a bestialFailure, it needs pool.hunger');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aHungerFormulaStillEnablesTheOutcomesNeedingHungerDice(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'checks.1.pool.hunger.count', ['formula' => '{hunger}']);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
    }

    #[Test]
    public function aSpecialOutcomeOnACheckSetNeedsEveryCheckToProduceIt(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'sceneTypes.0.play.1', [
            'key' => 'resist', 'kind' => 'check', 'title' => 'Resist', 'checks' => ['frenzy', 'persuade'],
            'outcomes' => [['special' => 'bestialFailure', 'next' => 'compulsion']],
        ]);
        self::assertNotSame('', ReleaseContent::fromArray($release)->hash(), 'Both checks roll Hunger dice.');

        $release = ReleaseArrays::with($release, 'sceneTypes.0.play.1.checks', ['frenzy', 'aim']);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[0].play[1].outcomes[0].special: check "aim" never has a bestialFailure, it needs pool.hunger');

        ReleaseContent::fromArray($release);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in valid/v2-hunger-dice, its new value and the message
     */
    public static function refusals(): iterable
    {
        $outcome = 'sceneTypes.0.play.0.outcomes';

        yield 'criticals that are not an object' => ['checks.0.pool.criticals', 2, 'checks[0].pool.criticals: must be an object'];
        yield 'criticals without a bonus' => ['checks.0.pool.criticals', [], 'checks[0].pool.criticals.bonus: required'];
        yield 'criticals with an unknown property' => ['checks.0.pool.criticals', ['bonus' => 2, 'face' => 10], 'checks[0].pool.criticals.face: unknown property'];
        yield 'a criticals bonus of zero' => ['checks.0.pool.criticals.bonus', 0, 'checks[0].pool.criticals.bonus: must be at least 1, 0 given'];
        yield 'a criticals bonus of 11' => ['checks.0.pool.criticals.bonus', 11, 'checks[0].pool.criticals.bonus: must be at most 10, 11 given'];
        yield 'Hunger that is a bare integer' => ['checks.0.pool.hunger', 2, 'checks[0].pool.hunger: must be an object'];
        yield 'Hunger without a count' => ['checks.0.pool.hunger', [], 'checks[0].pool.hunger.count: required'];
        yield 'Hunger with an unknown property' => ['checks.0.pool.hunger', ['count' => 1, 'colour' => 'red'], 'checks[0].pool.hunger.colour: unknown property'];
        yield 'a Hunger count of -1' => ['checks.0.pool.hunger.count', -1, 'checks[0].pool.hunger.count: must be at least 0, -1 given'];
        yield 'a Hunger count of 101' => ['checks.0.pool.hunger.count', 101, 'checks[0].pool.hunger.count: must be at most 100, 101 given'];
        yield 'a Hunger count that is a string' => ['checks.0.pool.hunger.count', '2', 'checks[0].pool.hunger.count: must be an integer or {formula: string}'];
        yield 'a Hunger formula reading an unknown field' => ['checks.0.pool.hunger.count.formula', '{ghost}', 'checks[0].pool.hunger.count.formula: {ghost}: unknown field "ghost"'];
        yield 'a Hunger formula reading the difficulty' => ['checks.0.pool.hunger.count.formula', '{check:difficulty}', 'checks[0].pool.hunger.count.formula: {check:difficulty}: a check reference is read only by the bands of a check'];
        yield 'a push cap of zero' => ['checks.0.push.maxDice', 0, 'checks[0].push.maxDice: must be at least 1, 0 given'];
        yield 'a push cap of 101' => ['checks.0.push.maxDice', 101, 'checks[0].push.maxDice: must be at most 100, 101 given'];
        yield 'a push cap that is a string' => ['checks.0.push.maxDice', '3', 'checks[0].push.maxDice: must be an integer'];
        yield 'an outcome with a band and a special' => [$outcome.'.0.special', 'critical', 'sceneTypes[0].play[0].outcomes[0]: name either a band or a special result, not both'];
        yield 'an outcome with neither' => [$outcome.'.0', ['next' => 'compulsion'], 'sceneTypes[0].play[0].outcomes[0].band: required unless the outcome names a special result'];
        yield 'an unknown special' => [$outcome.'.1.special', 'brutal', 'sceneTypes[0].play[0].outcomes[1].special: must be one of "critical", "messyCritical", "bestialFailure", \'brutal\' given'];
        yield 'a repeated special' => [$outcome.'.3.special', 'messyCritical', 'sceneTypes[0].play[0].outcomes[3].special: duplicate outcome special result "messyCritical"'];
        yield 'a critical without critical pairs' => ['sceneTypes.0.play.1.outcomes.0.special', 'critical', 'sceneTypes[0].play[1].outcomes[0].special: check "frenzy" never has a critical, it needs pool.criticals'];
        yield 'a messy critical without critical pairs' => ['sceneTypes.0.play.1.outcomes.0.special', 'messyCritical', 'sceneTypes[0].play[1].outcomes[0].special: check "frenzy" never has a messyCritical, it needs pool.criticals and pool.hunger'];
        yield 'a bestial failure without Hunger dice' => ['sceneTypes.0.play.1.check', 'aim', 'sceneTypes[0].play[1].outcomes[0].special: check "aim" never has a bestialFailure, it needs pool.hunger'];
        yield 'a messy critical without Hunger dice' => ['sceneTypes.0.play.1', ['key' => 'resist', 'kind' => 'check', 'title' => 'Aim', 'check' => 'aim', 'outcomes' => [['special' => 'messyCritical']]], 'sceneTypes[0].play[1].outcomes[0].special: check "aim" never has a messyCritical, it needs pool.criticals and pool.hunger'];
        yield 'a special on a dice check' => ['checks.1', ['key' => 'frenzy', 'label' => 'Frenzy', 'dice' => '1d10', 'bands' => [['key' => 'fail', 'label' => 'Failure', 'upTo' => 5], ['key' => 'win', 'label' => 'Win']]], 'sceneTypes[0].play[1].outcomes[0].special: check "frenzy" never has a bestialFailure, it needs pool.hunger'];
        yield 'more than 13 outcomes' => [$outcome, array_fill(0, 14, ['band' => 'fail']), 'sceneTypes[0].play[0].outcomes: at most 13 outcomes, 14 given'];
        yield 'an unknown badge' => ['gameSystem.credits.badge', 'camarilla', 'gameSystem.credits.badge: must be one of "dark-pack", \'camarilla\' given'];
        yield 'a badge that is not a string' => ['gameSystem.credits.badge', true, 'gameSystem.credits.badge: must be one of "dark-pack", true given'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aMalformedPartIsRefusedWithItsPath(string $path, mixed $value, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), $path, $value);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }
}
