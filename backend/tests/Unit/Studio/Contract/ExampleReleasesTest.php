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
 * scene that does not lower its tracker: the forced Firefight leaves the alarm as it is. The Campaign
 * facts parts (slot hints, fillFact, {fact:key}) change none either. The
 * Threads and NPCs parts (tags, pick steps, createNpc / createThread / closeThread) change no
 * warning: only the tracker conditions are checked.
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function exampleNames(): iterable
    {
        foreach (array_keys(self::WARNINGS) as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('exampleNames')]
    public function everyExampleDeclaresTagsAndUsesThreadsAndNpcs(string $name): void
    {
        $release = ReleaseArrays::fixture('examples/'.$name);

        self::assertNotSame([], $release['tags'] ?? []);
        self::assertTrue(self::declaresEffect($release, ['createNpc', 'createThread']), 'No createNpc or createThread effect found.');
    }

    #[Test]
    #[DataProvider('exampleNames')]
    public function everyExampleFillsEveryFactSlotItDeclares(string $name): void
    {
        $release = ReleaseArrays::fixture('examples/'.$name);
        /** @var list<array{key: string}> $slots */
        $slots = $release['factSlots'];

        $filled = array_values(array_unique(self::filledSlots($release)));
        $declared = array_column($slots, 'key');
        sort($filled);
        sort($declared);

        self::assertSame($declared, $filled);
    }

    #[Test]
    public function theChronicleGoalsAndTheMarchesExpeditionCarryHints(): void
    {
        foreach (['vtm-chronicle' => ['ambition', 'desire'], 'west-marches' => ['expedition']] as $name => $keys) {
            /** @var list<array{key: string, hint?: string}> $slots */
            $slots = ReleaseArrays::fixture('examples/'.$name)['factSlots'];
            $hinted = array_column(array_filter($slots, static fn (array $slot): bool => isset($slot['hint'])), 'key');

            self::assertSame($keys, $hinted, $name);
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string> the slot of every fillFact Effect in the node
     */
    private static function filledSlots(array $node): array
    {
        $slots = 'fillFact' === ($node['kind'] ?? null) && \is_string($node['slot'] ?? null) ? [$node['slot']] : [];
        foreach ($node as $child) {
            if (\is_array($child)) {
                $slots = [...$slots, ...self::filledSlots($child)];
            }
        }

        return $slots;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $kinds
     */
    private static function declaresEffect(array $node, array $kinds): bool
    {
        if (\is_string($node['kind'] ?? null) && \in_array($node['kind'], $kinds, true)) {
            return true;
        }

        return array_any($node, static fn ($child): bool => \is_array($child) && self::declaresEffect($child, $kinds));
    }

    #[Test]
    public function theMarchesSheetHasOneFieldOfEachType(): void
    {
        $release = ReleaseContent::fromArray(ReleaseArrays::fixture('examples/west-marches'))->toArray();

        self::assertIsArray($release['sheet']);
        /** @var list<array{type: string}> $fields */
        $fields = $release['sheet']['fields'];
        $types = array_values(array_unique(array_column($fields, 'type')));
        sort($types);
        self::assertSame(['list', 'number', 'text', 'track'], $types);
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
