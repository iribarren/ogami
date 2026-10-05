<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseContent::class)]
#[CoversClass(InvalidReleaseContent::class)]
final class ReleaseContentTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function release(): array
    {
        return [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => 'free-journal', 'name' => 'Free journal', 'description' => 'Any setting.'],
            'oracles' => [
                'tables' => [
                    ['key' => 'weather', 'name' => 'Weather', 'dice' => '1d6', 'entries' => [
                        ['min' => 1, 'max' => 4, 'text' => 'Clear'],
                        ['min' => 5, 'max' => 6, 'text' => 'Storm', 'table' => 'storm-kind'],
                    ]],
                    ['key' => 'storm-kind', 'name' => 'Storm kind', 'entries' => [
                        ['text' => 'Rain', 'weight' => 2],
                        ['text' => 'Hail'],
                    ]],
                ],
                'likelihood' => [
                    ['key' => 'fate', 'name' => 'Fate question', 'sides' => 100,
                        'levels' => [['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35], ['key' => 'likely', 'label' => 'Likely', 'target' => 65]],
                        'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
                        'exceptionalPercent' => 20],
                ],
            ],
            'flow' => ['steps' => [['key' => 'set-scene', 'title' => 'Set the scene', 'prompt' => 'Where are you?']]],
            'sheet' => [],
            'checks' => [],
        ];
    }

    #[Test]
    public function itAcceptsAValidRelease(): void
    {
        $content = ReleaseContent::fromArray(self::release());

        self::assertSame(1, $content->schemaVersion());
        self::assertSame('free-journal', $content->gameSystemKey());
        self::assertSame('Free journal', $content->gameSystemName());
    }

    #[Test]
    public function itAcceptsEmptyOracleListsAndAnEmptyFlow(): void
    {
        $release = self::with(self::release(), 'oracles', ['tables' => [], 'likelihood' => []]);
        $release = self::with($release, 'flow', ['steps' => []]);
        $release = self::without($release, 'gameSystem.description');

        $content = ReleaseContent::fromArray($release);

        self::assertSame(
            '{"schemaVersion":1,"gameSystem":{"key":"free-journal","name":"Free journal"},"oracles":{"tables":[],"likelihood":[]},"flow":{"steps":[]},"sheet":{},"checks":[]}',
            json_encode($content->toArray(), \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itTreatsNullOptionalsAsAbsent(): void
    {
        $release = self::release();
        foreach ([
            'gameSystem.description', 'oracles.tables.1.dice', 'oracles.tables.1.entries.1.weight', 'oracles.tables.1.entries.1.min',
            'oracles.tables.1.entries.1.max', 'oracles.tables.1.entries.1.table', 'oracles.likelihood.0.chaos',
            'oracles.likelihood.0.exceptionalPercent', 'flow.steps.0.prompt',
        ] as $path) {
            $release = self::with($release, $path, null);
        }

        $withoutNulls = self::release();
        foreach (['gameSystem.description', 'oracles.likelihood.0.chaos', 'oracles.likelihood.0.exceptionalPercent', 'flow.steps.0.prompt'] as $path) {
            $withoutNulls = self::without($withoutNulls, $path);
        }

        $content = ReleaseContent::fromArray($release);

        self::assertSame(ReleaseContent::fromArray($withoutNulls)->hash(), $content->hash());
        self::assertStringNotContainsString('null', json_encode($content->toArray(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itNormalizesIntegerValuedFloatsToIntegers(): void
    {
        $release = self::release();
        foreach ([
            'schemaVersion' => 1.0, 'oracles.tables.0.entries.0.min' => 1.0, 'oracles.tables.1.entries.0.weight' => 2.0,
            'oracles.likelihood.0.sides' => 100.0, 'oracles.likelihood.0.levels.0.target' => 35.0,
            'oracles.likelihood.0.chaos.neutral' => 5.0, 'oracles.likelihood.0.exceptionalPercent' => 20.0,
        ] as $path => $value) {
            $release = self::with($release, $path, $value);
        }

        $content = ReleaseContent::fromArray($release);
        $integers = ReleaseContent::fromArray(self::release());

        self::assertSame(1, $content->schemaVersion());
        self::assertSame(json_encode($integers->toArray()), json_encode($content->toArray()));
        self::assertSame($integers->hash(), $content->hash());
    }

    #[Test]
    public function itHashesTheCanonicalContentWhateverTheKeyOrder(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $reordered = ReleaseContent::fromArray(self::reverseKeys(self::release()));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $content->hash());
        self::assertSame($content->hash(), $reordered->hash());
        self::assertSame(['schemaVersion', 'gameSystem', 'oracles', 'flow', 'sheet', 'checks'], array_keys($reordered->toArray()));
        self::assertSame(
            hash('sha256', json_encode($content->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            $content->hash(),
        );
    }

    #[Test]
    public function differentContentHasADifferentHash(): void
    {
        $changed = self::with(self::release(), 'gameSystem.name', 'Free journal 2');

        self::assertNotSame(ReleaseContent::fromArray(self::release())->hash(), ReleaseContent::fromArray($changed)->hash());
    }

    #[Test]
    public function itAcceptsItsOwnCanonicalArray(): void
    {
        $content = ReleaseContent::fromArray(self::release());

        self::assertSame($content->hash(), ReleaseContent::fromArray($content->toArray())->hash());
    }

    #[Test]
    public function itRoundTripsItsOwnCanonicalJson(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($content->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidReleases(): iterable
    {
        $set = static fn (string $path, mixed $value): array => self::with(self::release(), $path, $value);
        $unset = static fn (string $path): array => self::without(self::release(), $path);
        $likelihood = static fn (int $i): array => ['key' => 'fate-'.$i, 'name' => 'Fate', 'sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]]];

        yield 'top level not an object' => [[self::release()], '(root): must be an object'];
        yield 'unknown top-level property' => [$set('extra', true), 'extra: unknown property'];
        yield 'missing top-level property' => [$unset('checks'), 'checks: required'];
        yield 'missing schema version' => [$unset('schemaVersion'), 'schemaVersion: required'];
        yield 'unsupported schema version' => [$set('schemaVersion', 2), 'schemaVersion: unsupported schema version'];
        yield 'schema version as string' => [$set('schemaVersion', '1'), 'schemaVersion: unsupported schema version'];
        yield 'fractional schema version' => [$set('schemaVersion', 1.5), 'schemaVersion: unsupported schema version'];
        yield 'game system not an object' => [$set('gameSystem', 'x'), 'gameSystem: must be an object'];
        yield 'unknown game system property' => [$set('gameSystem.author', 'me'), 'gameSystem.author: unknown property'];
        yield 'missing game system key' => [$unset('gameSystem.key'), 'gameSystem.key: required'];
        yield 'null game system key' => [$set('gameSystem.key', null), 'gameSystem.key: must not be null'];
        yield 'game system key not a string' => [$set('gameSystem.key', 7), 'gameSystem.key: must be a string'];
        yield 'bad game system key' => [$set('gameSystem.key', 'Free Journal'), 'gameSystem.key: must be 1 to 64 characters'];
        yield 'too long game system key' => [$set('gameSystem.key', str_repeat('a', 65)), 'gameSystem.key: must be 1 to 64 characters'];
        yield 'empty game system name' => [$set('gameSystem.name', ''), 'gameSystem.name: must not be empty'];
        yield 'whitespace-only game system name' => [$set('gameSystem.name', '   '), 'gameSystem.name: must not be blank'];
        yield 'too long game system name' => [$set('gameSystem.name', str_repeat('n', 101)), 'gameSystem.name: must be at most 100 characters'];
        yield 'too long description' => [$set('gameSystem.description', str_repeat('d', 2001)), 'gameSystem.description: must be at most 2000 characters'];
        yield 'description not a string' => [$set('gameSystem.description', 3), 'gameSystem.description: must be a string'];
        yield 'oracles not an object' => [$set('oracles', 'x'), 'oracles: must be an object'];
        yield 'unknown oracles property' => [$set('oracles.dice', []), 'oracles.dice: unknown property'];
        yield 'missing likelihood list' => [$unset('oracles.likelihood'), 'oracles.likelihood: required'];
        yield 'tables not a list' => [$set('oracles.tables', ['weather' => []]), 'oracles.tables: must be a list'];
        yield 'table not an object' => [$set('oracles.tables.0', 'weather'), 'oracles.tables[0]: must be an object'];
        yield 'unknown table property' => [$set('oracles.tables.0.color', 'red'), 'oracles.tables[0].color: unknown property'];
        yield 'missing table entries' => [$unset('oracles.tables.0.entries'), 'oracles.tables[0].entries: required'];
        yield 'entries not a list' => [$set('oracles.tables.0.entries', 'x'), 'oracles.tables[0].entries: must be a list'];
        yield 'unknown entry property' => [$set('oracles.tables.0.entries.1.note', 'x'), 'oracles.tables[0].entries[1].note: unknown property'];
        yield 'overlapping ranges (Randomness)' => [$set('oracles.tables.0.entries.1.min', 4), 'oracles.tables: '];
        yield 'nested cycle (Randomness)' => [$set('oracles.tables.1.entries.0.table', 'weather'), 'oracles.tables: '];
        yield 'duplicate table key (Randomness)' => [$set('oracles.tables.1.key', 'weather'), 'oracles.tables: '];
        yield 'fractional entry bound (Randomness)' => [$set('oracles.tables.0.entries.0.min', 1.5), 'oracles.tables: '];
        yield 'likelihood not a list' => [$set('oracles.likelihood', 'fate'), 'oracles.likelihood: must be a list'];
        yield 'too many likelihood oracles' => [$set('oracles.likelihood', array_map($likelihood, range(1, 21))), 'oracles.likelihood: at most 20 likelihood oracles'];
        yield 'likelihood not an object' => [$set('oracles.likelihood.0', 'fate'), 'oracles.likelihood[0]: must be an object'];
        yield 'unknown likelihood property' => [$set('oracles.likelihood.0.mode', 'x'), 'oracles.likelihood[0].mode: unknown property'];
        yield 'levels not a list' => [$set('oracles.likelihood.0.levels', 'x'), 'oracles.likelihood[0].levels: must be a list'];
        yield 'unknown level property' => [$set('oracles.likelihood.0.levels.0.color', 'x'), 'oracles.likelihood[0].levels[0].color: unknown property'];
        yield 'chaos not an object' => [$set('oracles.likelihood.0.chaos', 5), 'oracles.likelihood[0].chaos: must be an object'];
        yield 'unknown chaos property' => [$set('oracles.likelihood.0.chaos.step', 1), 'oracles.likelihood[0].chaos.step: unknown property'];
        yield 'missing likelihood key' => [$unset('oracles.likelihood.0.key'), 'oracles.likelihood[0].key: required'];
        yield 'bad likelihood key' => [$set('oracles.likelihood.0.key', 'Fate!'), 'oracles.likelihood[0].key: must be 1 to 64 characters'];
        yield 'missing likelihood name' => [$unset('oracles.likelihood.0.name'), 'oracles.likelihood[0].name: required'];
        yield 'whitespace-only likelihood name' => [$set('oracles.likelihood.0.name', ' '), 'oracles.likelihood[0].name: must not be blank'];
        yield 'too long likelihood name' => [$set('oracles.likelihood.0.name', str_repeat('n', 501)), 'oracles.likelihood[0].name: must be at most 500 characters'];
        yield 'level target above sides (Randomness)' => [$set('oracles.likelihood.0.levels.0.target', 101), 'oracles.likelihood[0]: '];
        yield 'fractional sides (Randomness)' => [$set('oracles.likelihood.0.sides', 100.5), 'oracles.likelihood[0]: '];
        yield 'oracle key shared by a table and a likelihood oracle' => [$set('oracles.likelihood.0.key', 'weather'), 'oracles.likelihood[0].key: duplicate oracle key "weather"'];
        yield 'duplicate likelihood key' => [$set('oracles.likelihood.1', [...$likelihood(1), 'key' => 'fate']), 'oracles.likelihood[1].key: duplicate oracle key "fate"'];
        yield 'flow without steps' => [$set('flow', []), 'flow.steps: required'];
        yield 'unknown flow property' => [$set('flow.start', 'x'), 'flow.start: unknown property'];
        yield 'steps not a list' => [$set('flow.steps', ['a' => []]), 'flow.steps: must be a list'];
        yield 'too many steps' => [
            $set('flow.steps', array_map(static fn (int $i): array => ['key' => 's-'.$i, 'title' => 'Step'], range(1, 101))),
            'flow.steps: at most 100 steps',
        ];
        yield 'step not an object' => [$set('flow.steps.0', 'set-scene'), 'flow.steps[0]: must be an object'];
        yield 'unknown step property' => [$set('flow.steps.0.oracle', 'fate'), 'flow.steps[0].oracle: unknown property'];
        yield 'missing step title' => [$unset('flow.steps.0.title'), 'flow.steps[0].title: required'];
        yield 'bad step key' => [$set('flow.steps.0.key', 'Set scene'), 'flow.steps[0].key: must be 1 to 64 characters'];
        yield 'whitespace-only step title' => [$set('flow.steps.0.title', '  '), 'flow.steps[0].title: must not be blank'];
        yield 'too long step title' => [$set('flow.steps.0.title', str_repeat('t', 101)), 'flow.steps[0].title: must be at most 100 characters'];
        yield 'too long step prompt' => [$set('flow.steps.0.prompt', str_repeat('p', 2001)), 'flow.steps[0].prompt: must be at most 2000 characters'];
        yield 'duplicate step key' => [$set('flow.steps.1', ['key' => 'set-scene', 'title' => 'Again']), 'flow.steps[1].key: duplicate step key "set-scene"'];
        yield 'non-empty sheet' => [$set('sheet', ['hp' => 10]), 'sheet: not supported in schema version 1'];
        yield 'sheet not an object' => [$set('sheet', 'x'), 'sheet: not supported in schema version 1'];
        yield 'non-empty checks' => [$set('checks', [['key' => 'climb']]), 'checks: not supported in schema version 1'];
        yield 'checks not a list' => [$set('checks', 'x'), 'checks: not supported in schema version 1'];
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

    #[Test]
    public function itKeepsTheRandomnessMessageWhenWrappingIt(): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageMatches('/^oracles\.likelihood\[0\]: .*unlikely.*101/');

        ReleaseContent::fromArray(self::with(self::release(), 'oracles.likelihood.0.levels.0.target', 101));
    }

    /**
     * Sets the value at a dotted path ("oracles.tables.0.name"), creating the last segment.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function with(array $node, string $path, mixed $value): array
    {
        $segments = explode('.', $path, 2);
        $segment = $segments[0];
        $rest = $segments[1] ?? null;
        if (null === $rest) {
            $node[$segment] = $value;

            return $node;
        }

        $child = $node[$segment] ?? null;
        if (!\is_array($child)) {
            throw new \LogicException(\sprintf('No array at "%s".', $segment));
        }

        $node[$segment] = self::with($child, $rest, $value);

        return $node;
    }

    /**
     * Removes the value at a dotted path.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function without(array $node, string $path): array
    {
        $segments = explode('.', $path, 2);
        $segment = $segments[0];
        $rest = $segments[1] ?? null;
        if (null === $rest) {
            unset($node[$segment]);

            return $node;
        }

        $child = $node[$segment] ?? null;
        if (!\is_array($child)) {
            throw new \LogicException(\sprintf('No array at "%s".', $segment));
        }

        $node[$segment] = self::without($child, $rest);

        return $node;
    }

    /**
     * Reverses the key order of every object (string-keyed array), keeping lists in order.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function reverseKeys(array $node): array
    {
        $node = array_map(static fn (mixed $value): mixed => \is_array($value) ? self::reverseKeys($value) : $value, $node);

        return array_is_list($node) ? $node : array_reverse($node, true);
    }
}
