<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;
use App\Studio\Domain\Release\ReleaseOracles;
use App\Studio\Domain\Release\Version2\Bands;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Schema version 2 rules, each against the fixture valid/v2-catalog.json. Its indexes: tables
 * events (0: patrol, lucky-break, run) and escape-routes (1); likelihood oracles fate (0, chaos
 * bound to "chaos") and omen (1); trackers alarm (0, clock), edge (1, counter with levels), chaos
 * (2, counter 1..9) and heat (3); fact slots target (0), client (1) and ambition (2).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseFields::class)]
#[CoversClass(ReleaseOracles::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Bands::class)]
final class ReleaseContentVersion2Test extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function release(): array
    {
        return ReleaseArrays::fixture('valid/v2-catalog');
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private static function set(string $path, mixed $value, ?array $release = null): array
    {
        return ReleaseArrays::with($release ?? self::release(), $path, $value);
    }

    /**
     * @return array<mixed>
     */
    private static function unset(string $path): array
    {
        return ReleaseArrays::without(self::release(), $path);
    }

    #[Test]
    public function itAcceptsTheCatalog(): void
    {
        $content = ReleaseContent::fromArray(self::release());

        self::assertSame(2, $content->schemaVersion());
        self::assertSame('catalog', $content->gameSystemKey());
        self::assertSame(['schemaVersion', 'gameSystem', 'oracles', 'trackers', 'factSlots', 'sceneTypes', 'flows', 'sheet', 'checks'], array_keys($content->toArray()));
        self::assertSame(self::release()['trackers'], $content->toArray()['trackers']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function validVariants(): iterable
    {
        yield 'an entry key repeated in another table' => [self::set('oracles.tables.1.entries.2.key', 'patrol')];
        yield 'a counter with min equal to max' => [self::set('trackers.1', ['key' => 'edge', 'name' => 'Edge', 'kind' => 'counter', 'min' => 2, 'max' => 2, 'initial' => 2])];
        yield 'a single level catching every value' => [self::set('trackers.1.levels', [['label' => 'Any']])];
        yield 'counters and clocks sharing no namespace with oracles' => [self::set('trackers.0.key', 'fate')];
        yield 'empty entry effects' => [self::set('oracles.tables.0.entries.0.effects', [])];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('validVariants')]
    public function itAcceptsValidVariants(array $release): void
    {
        self::assertSame(2, ReleaseContent::fromArray($release)->schemaVersion());
    }

    #[Test]
    public function itOmitsEmptyOptionalLists(): void
    {
        $empty = self::set('trackers.2.levels', [], self::set('oracles.tables.0.entries.0.effects', []));

        self::assertSame(json_encode(ReleaseContent::fromArray(self::release())->toArray()), json_encode(ReleaseContent::fromArray($empty)->toArray()));
    }

    #[Test]
    public function itHashesTheCanonicalContentWhateverTheKeyOrder(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $reordered = ReleaseContent::fromArray(ReleaseArrays::reverseKeys(self::release()));

        self::assertSame($content->hash(), $reordered->hash());
        self::assertSame(json_encode($content->toArray()), json_encode($reordered->toArray()));
        self::assertSame(hash('sha256', json_encode($content->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)), $content->hash());
    }

    #[Test]
    public function itAcceptsItsOwnCanonicalArrayAndJson(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($content->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame($content->hash(), ReleaseContent::fromArray($content->toArray())->hash());
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidReleases(): iterable
    {
        $tracker = static fn (array $tracker): array => self::set('trackers.1', $tracker);
        $counter = ['key' => 'edge', 'name' => 'Edge', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0];

        // Top level.
        yield 'unsupported schema version' => [self::set('schemaVersion', 3), 'schemaVersion: unsupported schema version 3, expected 1 or 2'];
        yield 'the flow of version 1' => [self::set('flow', ['steps' => []]), 'flow: unknown property'];
        yield 'missing trackers' => [self::unset('trackers'), 'trackers: required'];
        yield 'missing fact slots' => [self::unset('factSlots'), 'factSlots: required'];
        yield 'missing Scene Types' => [self::unset('sceneTypes'), 'sceneTypes: required'];
        yield 'missing flows' => [self::unset('flows'), 'flows: required'];
        yield 'Scene Types not a list' => [self::set('sceneTypes', 'legwork'), 'sceneTypes: must be a list'];
        yield 'Scene Types not supported yet' => [self::set('sceneTypes', [['key' => 'legwork']]), 'sceneTypes: not supported yet'];
        yield 'flows not supported yet' => [self::set('flows', [['key' => 'heist']]), 'flows: not supported yet'];
        yield 'non-empty sheet' => [self::set('sheet', ['hp' => 1]), 'sheet: not supported in schema version 2'];
        yield 'non-empty checks' => [self::set('checks', [1]), 'checks: not supported in schema version 2'];

        // Oracle table entries and chaos.
        yield 'bad entry key' => [self::set('oracles.tables.0.entries.0.key', 'Patrol'), 'oracles.tables[0].entries[0].key: must be 1 to 64 characters'];
        yield 'duplicate entry key' => [self::set('oracles.tables.0.entries.1.key', 'patrol'), 'oracles.tables[0].entries[1].key: duplicate entry key "patrol"'];
        yield 'unknown entry property' => [self::set('oracles.tables.0.entries.0.note', 'x'), 'oracles.tables[0].entries[0].note: unknown property'];
        yield 'entry Scene Type not supported yet' => [self::set('oracles.tables.0.entries.0.sceneType', 'legwork'), 'oracles.tables[0].entries[0].sceneType: not supported yet'];
        yield 'entry effects not supported yet' => [self::set('oracles.tables.0.entries.0.effects', [['kind' => 'endPhase']]), 'oracles.tables[0].entries[0].effects: not supported yet'];
        yield 'entries still checked by Randomness' => [self::set('oracles.tables.0.entries.1.min', 3), 'oracles.tables: '];
        yield 'unknown chaos property' => [self::set('oracles.likelihood.0.chaos.step', 1), 'oracles.likelihood[0].chaos.step: unknown property'];
        yield 'bad chaos tracker key' => [self::set('oracles.likelihood.0.chaos.tracker', 'Chaos'), 'oracles.likelihood[0].chaos.tracker: must be 1 to 64 characters'];
        yield 'unknown chaos tracker' => [self::set('oracles.likelihood.0.chaos.tracker', 'noise'), 'oracles.likelihood[0].chaos.tracker: unknown tracker "noise"'];
        yield 'chaos tracker a clock' => [self::set('oracles.likelihood.0.chaos.tracker', 'alarm'), 'oracles.likelihood[0].chaos.tracker: must be a counter, "alarm" is a clock'];
        yield 'chaos tracker of another range' => [self::set('trackers.2.max', 10), 'oracles.likelihood[0].chaos.tracker: counter "chaos" must range 1..9 like the chaos factor, 1..10 given'];
        yield 'chaos still checked by Randomness' => [self::set('oracles.likelihood.0.chaos.neutral', 10), 'oracles.likelihood[0]: '];

        // Trackers.
        yield 'trackers not a list' => [self::set('trackers', 'alarm'), 'trackers: must be a list'];
        yield 'too many trackers' => [self::set('trackers', array_map(static fn (int $i): array => ['key' => 't-'.$i, 'name' => 'T', 'kind' => 'clock', 'segments' => 2], range(1, 51))), 'trackers: at most 50 trackers, 51 given'];
        yield 'tracker not an object' => [self::set('trackers.0', 'alarm'), 'trackers[0]: must be an object'];
        yield 'tracker without kind' => [self::unset('trackers.0.kind'), 'trackers[0].kind: required'];
        yield 'unknown tracker kind' => [self::set('trackers.0.kind', 'dial'), 'trackers[0].kind: must be one of "counter", "clock", \'dial\' given'];
        yield 'clock with counter fields' => [self::set('trackers.0.min', 0), 'trackers[0].min: unknown property'];
        yield 'counter with clock fields' => [self::set('trackers.1.segments', 4), 'trackers[1].segments: unknown property'];
        yield 'duplicate tracker key' => [self::set('trackers.1.key', 'alarm'), 'trackers[1].key: duplicate tracker key "alarm"'];
        yield 'blank tracker name' => [self::set('trackers.0.name', ' '), 'trackers[0].name: must not be blank'];
        yield 'too long tracker name' => [self::set('trackers.0.name', str_repeat('n', 101)), 'trackers[0].name: must be at most 100 characters'];
        yield 'too long tracker hint' => [self::set('trackers.0.hint', str_repeat('h', 501)), 'trackers[0].hint: must be at most 500 characters'];
        yield 'no clock segments' => [self::set('trackers.0.segments', 0), 'trackers[0].segments: must be at least 1, 0 given'];
        yield 'too many clock segments' => [self::set('trackers.0.segments', 21), 'trackers[0].segments: must be at most 20, 21 given'];
        yield 'fractional clock segments' => [self::set('trackers.0.segments', 2.5), 'trackers[0].segments: must be an integer'];
        yield 'counter min below bound' => [$tracker(['min' => -1001] + $counter), 'trackers[1].min: must be at least -1000, -1001 given'];
        yield 'counter max above bound' => [$tracker(['max' => 1001] + $counter), 'trackers[1].max: must be at most 1000, 1001 given'];
        yield 'counter max below min' => [$tracker(['min' => 3, 'max' => 0] + $counter), 'trackers[1].max: must be at least min (3), 0 given'];
        yield 'counter initial above max' => [$tracker(['initial' => 4] + $counter), 'trackers[1].initial: must be within min..max (0..3), 4 given'];
        yield 'counter initial below min' => [$tracker(['initial' => -1] + $counter), 'trackers[1].initial: must be within min..max (0..3), -1 given'];
        yield 'levels not a list' => [self::set('trackers.1.levels', 'low'), 'trackers[1].levels: must be a list'];
        yield 'last level with upTo' => [self::set('trackers.1.levels.1.upTo', 3), 'trackers[1].levels[1].upTo: the last level catches the rest and must omit upTo'];
        yield 'level without upTo before the last' => [self::unset('trackers.1.levels.0.upTo'), 'trackers[1].levels[0].upTo: required, only the last level omits it'];
        yield 'level upTo not increasing' => [self::set('trackers.3.levels.1.upTo', -1), 'trackers[3].levels[1].upTo: must be greater than -1, the previous upTo, -1 given'];
        yield 'level upTo a tracker' => [self::set('trackers.1.levels.0.upTo', ['tracker' => 'alarm']), 'trackers[1].levels[0].upTo: must be an integer'];
        yield 'unknown level property' => [self::set('trackers.1.levels.0.color', 'red'), 'trackers[1].levels[0].color: unknown property'];
        yield 'empty level label' => [self::set('trackers.1.levels.0.label', ''), 'trackers[1].levels[0].label: must not be empty'];
        yield 'too many levels' => [self::set('trackers.1.levels', [...array_map(static fn (int $i): array => ['upTo' => $i, 'label' => 'L'], range(1, 20)), ['label' => 'Rest']]), 'trackers[1].levels: at most 20 levels, 21 given'];

        // Fact slots.
        yield 'fact slots not a list' => [self::set('factSlots', 'target'), 'factSlots: must be a list'];
        yield 'unknown fact slot type' => [self::set('factSlots.0.type', 'place'), 'factSlots[0].type: must be one of "text", "npc", "thread", \'place\' given'];
        yield 'unknown fact slot property' => [self::set('factSlots.0.hint', 'Who?'), 'factSlots[0].hint: unknown property'];
        yield 'duplicate fact slot key' => [self::set('factSlots.1.key', 'target'), 'factSlots[1].key: duplicate fact slot key "target"'];
        yield 'blank fact slot label' => [self::set('factSlots.0.label', ' '), 'factSlots[0].label: must not be blank'];
        yield 'too long fact slot label' => [self::set('factSlots.0.label', str_repeat('l', 101)), 'factSlots[0].label: must be at most 100 characters'];
        yield 'too many fact slots' => [self::set('factSlots', array_map(static fn (int $i): array => ['key' => 's-'.$i, 'label' => 'S', 'type' => 'text'], range(1, 101))), 'factSlots: at most 100 fact slots, 101 given'];
    }

    /**
     * @param array<mixed>     $release
     * @param non-empty-string $expectedPrefix
     */
    #[Test]
    #[DataProvider('invalidReleases')]
    public function itRejectsInvalidContentNamingThePath(array $release, string $expectedPrefix): void
    {
        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent.');
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            self::assertStringStartsWith($expectedPrefix, $invalidReleaseContent->getMessage());
        }
    }
}
