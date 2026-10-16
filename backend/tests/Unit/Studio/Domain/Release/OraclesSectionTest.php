<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\OraclesSection;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The oracles section has one validator: the publisher (ReleaseContent::fromArray) and the Studio
 * oracle editor report the same error for the same section.
 */
#[CoversClass(OraclesSection::class)]
final class OraclesSectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, mixed, string}> fixture, path of the section, value, message start
     */
    public static function invalidSections(): iterable
    {
        $entries = [['text' => 'a']];
        yield 'v1 not an object' => ['valid/minimal', 'oracles', 'x', 'oracles: must be an object'];
        yield 'v1 unknown property' => ['valid/minimal', 'oracles', ['tables' => [], 'likelihood' => [], 'extra' => 1], 'oracles.extra: unknown property'];
        yield 'v1 tables not a list' => ['valid/minimal', 'oracles', ['tables' => 'x', 'likelihood' => []], 'oracles.tables: must be a list'];
        yield 'v1 entry key is v2 only' => ['valid/minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'a', 'key' => 'one']]]], 'oracles.tables[0].entries[0].key: unknown property'];
        yield 'v1 duplicate table keys' => ['valid/minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'entries' => $entries], ['key' => 'a', 'name' => 'B', 'entries' => $entries]], 'oracles.tables'];
        yield 'v1 likelihood key shared with a table' => ['valid/minimal', 'oracles', ['tables' => [['key' => 'a', 'name' => 'A', 'entries' => $entries]], 'likelihood' => [['key' => 'a', 'name' => 'A', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 50]]]]], 'oracles.likelihood[0].key: duplicate oracle key "a"'];
        yield 'v1 too many likelihood oracles' => ['valid/minimal', 'oracles.likelihood', array_fill(0, 21, ['key' => 'a']), 'oracles.likelihood: at most 20 likelihood oracles, 21 given'];
        yield 'v2 duplicate entry key' => ['valid/v2-minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'a', 'key' => 'one'], ['text' => 'b', 'key' => 'one']]]], 'oracles.tables[0].entries[1].key: duplicate entry key "one"'];
        yield 'v2 invalid entry key' => ['valid/v2-minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'a', 'key' => 'One']]]], 'oracles.tables[0].entries[0].key'];
        yield 'v2 unknown entry property' => ['valid/v2-minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'a', 'note' => 'x']]]], 'oracles.tables[0].entries[0].note: unknown property'];
        yield 'v2 random event table missing' => ['valid/v2-minimal', 'oracles', ['tables' => [], 'likelihood' => [['key' => 'fate', 'name' => 'Fate', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5], 'randomEvent' => ['table' => 'nowhere']]]], 'oracles.likelihood[0].randomEvent.table: unknown oracle table "nowhere"'];
        yield 'v2 unknown chaos property' => ['valid/v2-minimal', 'oracles', ['tables' => [], 'likelihood' => [['key' => 'fate', 'name' => 'Fate', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5, 'step' => 1]]]], 'oracles.likelihood[0].chaos.step: unknown property'];
        yield 'v2 overlapping ranges' => ['valid/v2-minimal', 'oracles.tables', [['key' => 'a', 'name' => 'A', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 4, 'text' => 'a'], ['min' => 4, 'max' => 6, 'text' => 'b']]]], 'oracles.tables: '];
    }

    /**
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('invalidSections')]
    public function theSectionAndThePublisherReportTheSameError(string $fixture, string $path, mixed $section, string $expected): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture($fixture), $path, $section);

        $published = $this->message(static fn (): ReleaseContent => ReleaseContent::fromArray($release));
        $alone = $this->message(static fn (): array => OraclesSection::validate($release['oracles'], 1 === $release['schemaVersion'] ? 1 : 2));

        self::assertStringStartsWith($expected, $published);
        self::assertSame($published, $alone);
    }

    #[Test]
    public function aValidSectionPassesForBothSchemaVersions(): void
    {
        foreach (['valid/contract-doc-example', 'valid/v2-every-part', 'valid/v2-random-event'] as $fixture) {
            $release = ReleaseArrays::fixture($fixture);
            /** @var array{tables: list<array<string, mixed>>} $oracles */
            $oracles = $release['oracles'];

            $section = OraclesSection::validate($oracles, 1 === $release['schemaVersion'] ? 1 : 2);

            self::assertSame(array_column($oracles['tables'], 'key'), array_column($section['tables'], 'key'));
            ReleaseContent::fromArray($release);
        }
    }

    private function message(callable $validate): string
    {
        try {
            $validate();
        } catch (InvalidReleaseContent $invalid) {
            return $invalid->getMessage();
        }

        self::fail('Expected InvalidReleaseContent');
    }
}
