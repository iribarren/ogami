<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Contract;

use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The five flow examples of docs/domain/flow-examples.md as schema version 2 releases (ADR 0018,
 * decision 26). ReleaseSchemaAgreementTest checks them against the schema and the domain; this
 * test pins the authoring warnings each one yields (decision 7). Only the heist accepts a forced
 * scene that does not lower its tracker: the forced Firefight leaves the alarm as it is.
 */
#[CoversNothing]
final class ExampleReleasesTest extends TestCase
{
    private const string EXAMPLES = __DIR__.'/../../../Fixtures/Studio/releases/examples';

    /**
     * Expected warnings per example file.
     */
    private const array WARNINGS = [
        'vtm-chronicle' => [],
        'cpr-heist' => ['flows[0].phases[2].worldTurn[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn'],
        'mythic-session' => [],
        'cpr-campaign-in-acts' => [],
        'west-marches' => [],
    ];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function examples(): iterable
    {
        foreach (self::WARNINGS as $name => $warnings) {
            yield $name => [$name, $warnings];
        }
    }

    /**
     * @param list<string> $warnings
     */
    #[Test]
    #[DataProvider('examples')]
    public function itYieldsTheWarningsTheExampleIntends(string $name, array $warnings): void
    {
        self::assertSame($warnings, ReleaseContent::fromArray(ReleaseArrays::fixture('examples/'.$name))->warnings());
    }

    #[Test]
    public function everyExampleFileHasItsExpectedWarnings(): void
    {
        $files = array_map(static fn (string $file): string => basename($file, '.json'), glob(self::EXAMPLES.'/*.json') ?: []);
        sort($files);
        $expected = array_keys(self::WARNINGS);
        sort($expected);

        self::assertSame($expected, $files);
    }
}
