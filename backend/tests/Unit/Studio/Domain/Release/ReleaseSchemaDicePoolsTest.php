<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Tests\Support\Studio\ReleaseArrays;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shapes of ADR 0041 and 0044 in the schema version 2 file on their own: what the schema refuses
 * structurally, and the rules it leaves to the domain (ReleaseContentChecksTest and its siblings
 * cover those), run against valid/v2-dice-pools.
 */
#[CoversNothing]
final class ReleaseSchemaDicePoolsTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v2.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../../contracts/gamesystem-release/v2.schema.json';

    /**
     * @return iterable<string, array{string, mixed}> the path changed in valid/v2-dice-pools and its new value; the schema refuses each
     */
    public static function structuralRefusals(): iterable
    {
        $effect = 'sceneTypes.0.play.0.outcomes.0.effects.0';

        yield 'dice beside a pool' => ['checks.0.dice', '1d6'];
        yield 'a pool of null and no dice' => ['checks.0.pool', null];
        yield 'a pool with an unknown property' => ['checks.0.pool.explode', 1];
        yield 'criticals without a bonus' => ['checks.0.pool.criticals', []];
        yield 'a criticals bonus of zero' => ['checks.0.pool.criticals', ['bonus' => 0]];
        yield 'a criticals bonus of 11' => ['checks.0.pool.criticals', ['bonus' => 11]];
        yield 'criticals with an unknown property' => ['checks.0.pool.criticals', ['bonus' => 2, 'face' => 10]];
        yield 'a Hunger count that is a bare integer' => ['checks.0.pool.hunger', 1];
        yield 'a Hunger count of -1' => ['checks.0.pool.hunger', ['count' => -1]];
        yield 'a Hunger count of 101' => ['checks.0.pool.hunger', ['count' => 101]];
        yield 'Hunger with an unknown property' => ['checks.0.pool.hunger', ['count' => 1, 'colour' => 'red']];
        yield 'a push cap of zero' => ['checks.0.push.maxDice', 0];
        yield 'a push cap of 101' => ['checks.0.push.maxDice', 101];
        yield 'an outcome with a band and a special' => ['sceneTypes.0.play.0.outcomes.0.special', 'critical'];
        yield 'an outcome with neither a band nor a special' => ['sceneTypes.0.play.0.outcomes.0', ['next' => 'slip-out']];
        yield 'an unknown special' => ['sceneTypes.0.play.0.outcomes.0', ['special' => 'brutal']];
        yield 'more than 13 outcomes' => ['sceneTypes.0.play.0.outcomes', array_fill(0, 14, ['band' => 'fail'])];
        yield 'an unknown badge' => ['gameSystem.credits', ['notice' => 'Fan content.', 'badge' => 'camarilla']];
        yield 'a pool without sides' => ['checks.0.pool', ['count' => 1, 'successAt' => 6]];
        yield 'a count of zero' => ['checks.0.pool.count', 0];
        yield 'a count of 101' => ['checks.0.pool.count', 101];
        yield 'a count that is a string' => ['checks.0.pool.count', '3'];
        yield 'a count formula with another property' => ['checks.0.pool.count', ['formula' => '3', 'min' => 1]];
        yield 'sides of one' => ['checks.0.pool.sides', 1];
        yield 'sides of 1001' => ['checks.0.pool.sides', 1001];
        yield 'successAt of zero' => ['checks.0.pool.successAt', 0];
        yield 'a push without a label' => ['checks.0.push', ['effects' => []]];
        yield 'a push label over 100 characters' => ['checks.0.push.label', str_repeat('a', 101)];
        yield 'a push with an unknown property' => ['checks.0.push.times', 2];
        yield 'more than 10 push effects' => ['checks.0.push.effects', array_fill(0, 11, ['kind' => 'sheet', 'field' => 'physical', 'op' => 'clear'])];
        yield 'a conditions field without options' => ['sheet.fields.2.options', []];
        yield 'a conditions field of 21 options' => ['sheet.fields.2.options', array_map(static fn (int $n): array => ['key' => 'c'.$n, 'label' => 'C'], range(1, 21))];
        yield 'an option with an unknown property' => ['sheet.fields.2.options.0.color', 'red'];
        yield 'an option without a label' => ['sheet.fields.2.options.0', ['key' => 'hurt']];
        yield 'an option label over 60 characters' => ['sheet.fields.2.options.0.label', str_repeat('a', 61)];
        yield 'a number property on a conditions field' => ['sheet.fields.2.max', 3];
        yield 'an add without a value' => [$effect, ['kind' => 'sheet', 'field' => 'agility', 'op' => 'add']];
        yield 'a set with an option key' => [$effect, ['kind' => 'sheet', 'field' => 'agility', 'op' => 'set', 'value' => 'hurt']];
        yield 'a mark with a number' => [$effect.'.value', 3];
        yield 'a mark with a formula' => [$effect.'.value', ['formula' => '1']];
        yield 'an unknown operation' => [$effect.'.op', 'toggle'];
    }

    #[Test]
    #[DataProvider('structuralRefusals')]
    public function theSchemaRefusesAMalformedShape(string $path, mixed $value): void
    {
        self::assertFalse($this->conforms(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), $path, $value)));
    }

    /**
     * @return iterable<string, array{string, mixed}> what the schema accepts and the domain refuses
     */
    public static function domainRules(): iterable
    {
        yield 'successAt above sides' => ['checks.0.pool.successAt', 7];
        yield 'a modifier with a pool' => ['checks.0.modifier', '{agility}'];
        yield 'a push on a check without a pool' => ['checks.1', ['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'push' => ['label' => 'Push'], 'bands' => [['key' => 'ok', 'label' => 'Ok']]]];
        yield 'a repeated option key' => ['sheet.fields.2.options.1.key', 'hurt'];
        yield 'a mark without an option in an outcome' => ['sceneTypes.0.play.0.outcomes.0.effects.0', ['kind' => 'sheet', 'field' => 'physical', 'op' => 'mark']];
        yield 'an unknown option' => ['sceneTypes.0.play.0.outcomes.0.effects.0.value', 'ghost'];
        yield 'a push effect of another kind' => ['checks.0.push.effects', [['kind' => 'endPhase']]];
        yield 'a special the check cannot produce' => ['sceneTypes.0.play.0.outcomes.0', ['special' => 'critical']];
        yield 'a repeated special' => ['sceneTypes.0.play.0.outcomes', [['special' => 'critical'], ['special' => 'critical']]];
        yield 'a Hunger formula reading an unknown field' => ['checks.0.pool.hunger', ['count' => ['formula' => '{ghost}']]];
    }

    #[Test]
    #[DataProvider('domainRules')]
    public function theSchemaLeavesTheseRulesToTheDomain(string $path, mixed $value): void
    {
        self::assertTrue($this->conforms(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), $path, $value)));
    }

    /**
     * @param array<mixed> $release
     */
    private function conforms(array $release): bool
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile(self::SCHEMA_ID, self::SCHEMA_FILE);

        return $validator->validate(json_decode(json_encode($release, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR), self::SCHEMA_ID)->isValid();
    }
}
