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
 * The shapes of ADR 0042 in the schema version 2 file on their own: what the schema refuses
 * structurally, and the rules it leaves to the domain (ReleaseContentFlowChoicesTest covers
 * those), run against valid/v2-flow-choices.
 */
#[CoversNothing]
final class ReleaseSchemaFlowChoicesTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v2.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../../contracts/gamesystem-release/v2.schema.json';

    /**
     * @return iterable<string, array{string, mixed}> the path changed in valid/v2-flow-choices and its new value; the schema refuses each
     */
    public static function structuralRefusals(): iterable
    {
        $check = 'sceneTypes.0.play.2';
        $table = 'sceneTypes.0.play.3';

        yield 'check and checks' => [$check.'.check', 'grit'];
        yield 'checks of null and no check' => [$check.'.checks', null];
        yield 'one check in the set' => [$check.'.checks', ['grit']];
        yield 'eleven checks in the set' => [$check.'.checks', array_fill(0, 11, 'grit')];
        yield 'a check key that breaks the key rule' => [$check.'.checks.1', 'Wits'];
        yield 'a check set that is an object' => [$check.'.checks', ['a' => 'grit', 'b' => 'wits']];
        yield 'a single check that is a list' => [$check, ['key' => 'defy', 'kind' => 'check', 'title' => 'Defy', 'check' => ['grit']]];
        yield 'an empty table map' => [$table.'.tableBySceneType', new \stdClass()];
        yield 'a table map that is a list' => [$table.'.tableBySceneType', ['wild-trouble']];
        yield 'a Scene Type key that breaks the key rule' => [$table.'.tableBySceneType', ['Wild' => 'wild-trouble']];
        yield 'a table key that breaks the key rule' => [$table.'.tableBySceneType.wild', 'Wild trouble'];
        yield 'a table that is a number' => [$table.'.tableBySceneType.wild', 7];
    }

    #[Test]
    #[DataProvider('structuralRefusals')]
    public function theSchemaRefusesAMalformedShape(string $path, mixed $value): void
    {
        $release = null === $value
            ? ReleaseArrays::without(ReleaseArrays::fixture('valid/v2-flow-choices'), $path)
            : ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-flow-choices'), $path, $value);

        self::assertFalse($this->conforms($release));
    }

    /**
     * @return iterable<string, array{string, mixed}> what the schema accepts and the domain refuses
     */
    public static function domainRules(): iterable
    {
        yield 'a repeated check' => ['sceneTypes.0.play.2.checks', ['grit', 'grit']];
        yield 'an unknown check' => ['sceneTypes.0.play.2.checks', ['grit', 'ghost']];
        yield 'checks with other bands' => ['sceneTypes.0.play.2.checks', ['grit', 'luck']];
        yield 'an unknown Scene Type in the map' => ['sceneTypes.0.play.3.tableBySceneType', ['ghost' => 'wild-trouble']];
        yield 'an unknown table in the map' => ['sceneTypes.0.play.3.tableBySceneType.wild', 'ghost'];
        yield 'branches with the map' => ['sceneTypes.0.play.3.branches', [['entry' => 'a-storm']]];
        yield 'a sheet effect on the skip option' => ['sceneTypes.0.play.0.options.1.effects', [['kind' => 'sheet', 'field' => 'xp', 'op' => 'add', 'value' => 1]]];
    }

    #[Test]
    #[DataProvider('domainRules')]
    public function theSchemaLeavesTheseRulesToTheDomain(string $path, mixed $value): void
    {
        self::assertTrue($this->conforms(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-flow-choices'), $path, $value)));
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
