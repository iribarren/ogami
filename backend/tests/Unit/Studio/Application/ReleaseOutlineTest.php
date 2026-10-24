<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\ReleaseOutline;
use App\Studio\Application\SectionCounts;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseOutline::class)]
final class ReleaseOutlineTest extends TestCase
{
    #[Test]
    public function aSchemaVersion2ReleaseListsEachSectionInContentOrderWithItsDisplayNames(): void
    {
        $outline = ReleaseOutline::of(self::document('valid/v2-contract-doc-example'), 2);

        self::assertSame(array_keys(SectionCounts::of([], 2)), array_keys($outline));
        self::assertSame([['key' => 'heist-scenes', 'name' => 'Heist scenes'], ['key' => 'complications', 'name' => 'Complications']], \array_slice($outline['oracleTables'], 0, 2));
        self::assertSame('legwork', $outline['sceneTypes'][0]['key']);
        self::assertSame('heist', $outline['flows'][0]['key']);
        self::assertSame('Heist', $outline['flows'][0]['name']);
    }

    #[Test]
    public function aSheetFieldAndACheckAreNamedByTheirLabel(): void
    {
        $outline = ReleaseOutline::of(self::document('valid/v2-sheet'), 2);

        self::assertSame(['key' => 'role', 'name' => 'Role'], $outline['sheetFields'][0]);
        self::assertSame(['key' => 'a-check', 'name' => 'A check'], ReleaseOutline::of(['checks' => [['key' => 'a-check', 'label' => 'A check']]], 2)['checks'][0]);
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProvider('everyReleaseWithItems')]
    public function everySectionHasAsManyItemsAsItsCount(array $document, int $schemaVersion): void
    {
        $outline = ReleaseOutline::of($document, $schemaVersion);
        $counts = SectionCounts::of($document, $schemaVersion);

        self::assertSame(array_keys($counts), array_keys($outline));
        self::assertSame($counts, array_map(\count(...), $outline));
    }

    /**
     * @return array<string, mixed> the canonical document of a fixture, as a stored release holds it
     */
    private static function document(string $fixture): array
    {
        return ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function everyReleaseWithItems(): iterable
    {
        yield 'every part' => [self::document('valid/v2-every-part'), 2];
        yield 'the contract example' => [self::document('valid/v2-contract-doc-example'), 2];
        yield 'a sheet' => [self::document('valid/v2-sheet'), 2];
        yield 'a schema version 1 release' => [self::document('valid/contract-doc-example'), 1];
        yield 'an empty release' => [[], 2];
    }

    #[Test]
    public function aSchemaVersion1ReleaseListsItsOraclesAndFlowSteps(): void
    {
        $outline = ReleaseOutline::of(self::document('valid/contract-doc-example'), 1);

        self::assertSame(['oracles', 'flowSteps'], array_keys($outline));
        self::assertCount(3, $outline['oracles']);
        self::assertSame(['weather', 'storm-kind', 'fate'], array_column($outline['oracles'], 'key'));
        self::assertSame([['key' => 'set-scene', 'name' => 'Set the scene']], $outline['flowSteps']);
    }

    #[Test]
    public function anItemWithoutADisplayNameHasANullName(): void
    {
        $outline = ReleaseOutline::of(['trackers' => [['key' => 'heat'], ['key' => 'edge', 'name' => '']], 'tags' => ['broken']], 2);

        self::assertSame([['key' => 'heat', 'name' => null], ['key' => 'edge', 'name' => null]], $outline['trackers']);
        self::assertSame([['key' => '', 'name' => null]], $outline['tags']);
    }
}
