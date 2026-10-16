<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\OracleInUse;
use App\Studio\Domain\GameSystem\OracleKeyTaken;
use App\Studio\Domain\GameSystem\OracleNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystem::class)]
#[CoversClass(OracleInUse::class)]
#[CoversClass(OracleKeyTaken::class)]
#[CoversClass(OracleNotFound::class)]
final class GameSystemOraclesTest extends TestCase
{
    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-15T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    private function draft(string $fixture = 'valid/v2-every-part'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), 'my-copy', 'My copy', null, 'user-1', $source, $this->created);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tables(GameSystem $gameSystem): array
    {
        /** @var array{tables: list<array<string, mixed>>} $oracles */
        $oracles = $gameSystem->document()['oracles'];

        return $oracles['tables'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function likelihood(GameSystem $gameSystem): array
    {
        /** @var array{likelihood: list<array<string, mixed>>} $oracles */
        $oracles = $gameSystem->document()['oracles'];

        return $oracles['likelihood'];
    }

    /**
     * @return list<string>
     */
    private function tableKeys(GameSystem $gameSystem): array
    {
        /** @var list<string> $keys */
        $keys = array_column($this->tables($gameSystem), 'key');

        return $keys;
    }

    /**
     * @param array<mixed> $node
     */
    private function at(array $node, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node)) {
                return null;
            }

            $node = $node[$segment] ?? $node[(int) $segment] ?? null;
        }

        return $node;
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertUntouched(GameSystem $gameSystem, callable $command, string $exception): GameSystem
    {
        $before = $gameSystem->document();
        try {
            $command();
            self::fail('Expected '.$exception);
        } catch (\Throwable $refusal) {
            self::assertInstanceOf($exception, $refusal);
        }

        self::assertSame($before, $gameSystem->document());
        self::assertEquals($this->created, $gameSystem->updatedAt());

        return $gameSystem;
    }

    #[Test]
    public function aNewTableIsAppendedAndUpdatesTheDraft(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 3, 'text' => 'True'], ['min' => 4, 'max' => 6, 'text' => 'False']]], $this->now);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes', 'rumours'], $this->tableKeys($gameSystem));
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::fromArray($gameSystem->document());
    }

    #[Test]
    public function aReplacedTableKeepsItsPositionAndItsExtensions(): void
    {
        $gameSystem = $this->draft();
        $table = $this->tables($gameSystem)[1];
        $table['name'] = 'Heist complications';
        $table = ReleaseArrays::with($table, 'entries.0.text', 'Patrol arrives');

        $gameSystem->saveOracleTable('complications', $table, $this->now);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($gameSystem));
        $saved = $this->tables($gameSystem)[1];
        self::assertSame('Heist complications', $saved['name']);
        /** @var list<array<string, mixed>> $entries */
        $entries = $saved['entries'];
        self::assertSame('Patrol arrives', $entries[0]['text']);
        self::assertSame('firefight', $entries[2]['sceneType']);
        self::assertSame('alarm', self::at($entries[0], 'effects.0.tracker'));
        ReleaseContent::fromArray($gameSystem->document());
    }

    #[Test]
    public function anUnreferencedTableCanBeRenamed(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]], $this->now);

        $gameSystem->saveOracleTable('rumours', ['key' => 'gossip', 'name' => 'Gossip', 'entries' => [['text' => 'True']]], $this->now);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes', 'gossip'], $this->tableKeys($gameSystem));
    }

    #[Test]
    public function aTableKeyUsedByAnotherTableOrALikelihoodOracleIsTaken(): void
    {
        $gameSystem = $this->draft();
        $entries = [['text' => 'x']];

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable(null, ['key' => 'fate', 'name' => 'N', 'entries' => $entries], $this->now), OracleKeyTaken::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable('heist-scenes', ['key' => 'complications', 'name' => 'N', 'entries' => $entries], $this->now), OracleKeyTaken::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveLikelihoodOracle(null, [...$this->likelihood($gameSystem)[0], 'key' => 'escape-routes'], $this->now), OracleKeyTaken::class);
    }

    #[Test]
    public function savingATableUnderItsOwnKeyIsNotACollision(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveOracleTable('escape-routes', $this->tables($gameSystem)[2], $this->now);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($gameSystem));
    }

    #[Test]
    public function replacingAnUnknownTableIsNotFound(): void
    {
        $gameSystem = $this->draft();

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable('missing', ['key' => 'missing', 'name' => 'N', 'entries' => [['text' => 'x']]], $this->now), OracleNotFound::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable('fate', ['key' => 'fate', 'name' => 'N', 'entries' => [['text' => 'x']]], $this->now), OracleNotFound::class);
    }

    #[Test]
    public function aReferencedTableCannotBeRenamedAndTheErrorListsWhere(): void
    {
        $gameSystem = $this->draft();
        $renamed = [...$this->tables($gameSystem)[2], 'key' => 'ways-out'];

        try {
            $gameSystem->saveOracleTable('escape-routes', $renamed, $this->now);
            self::fail('Expected OracleInUse');
        } catch (OracleInUse $inUse) {
            self::assertSame(['sceneTypes[3].oracles', 'flows[0].oracles', 'sceneTypes[3].setup[2]', 'oracles.tables[1].entries[3].table'], $inUse->paths());
            self::assertStringContainsString('flows[0].oracles', $inUse->getMessage());
        }

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($gameSystem));
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function aReferencedTableCannotBeDeleted(): void
    {
        $gameSystem = $this->draft();

        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteOracleTable('heist-scenes', $this->now), OracleInUse::class);
    }

    #[Test]
    public function aTableNestedOnlyByItselfIsNotBlockedByItsOwnEntries(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');

        // "storm-kind" is nested by "weather"; the other way round is free: "weather" is unused.
        $gameSystem->deleteOracleTable('weather', $this->now);

        self::assertSame(['storm-kind'], $this->tableKeys($gameSystem));
        self::assertEquals($this->now, $gameSystem->updatedAt());
    }

    #[Test]
    public function aTableNestedByAnotherCannotBeRenamedOrDeleted(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $stormKind = $this->tables($gameSystem)[1];

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable('storm-kind', [...$stormKind, 'key' => 'storms'], $this->now), OracleInUse::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteOracleTable('storm-kind', $this->now), OracleInUse::class);
    }

    #[Test]
    public function anEntryKeyUsedByATableStepBranchCannotBeRemovedOrRenamed(): void
    {
        $gameSystem = $this->draft();
        $complications = $this->tables($gameSystem)[1];

        $renamed = ReleaseArrays::with($complications, 'entries.2.key', 'seen');
        /** @var list<array<string, mixed>> $entries */
        $entries = $complications['entries'];
        $removed = [...$complications, 'entries' => [$entries[0], $entries[1], $entries[3]]];
        $thirdKey = $entries[2]['key'];
        self::assertIsString($thirdKey);

        foreach ([$renamed, $removed] as $table) {
            try {
                $gameSystem->saveOracleTable('complications', $table, $this->now);
                self::fail('Expected OracleInUse');
            } catch (OracleInUse $inUse) {
                self::assertSame(['sceneTypes[1].play[1].branches[0].entry'], $inUse->paths());
                self::assertSame(\sprintf('The entry "%s" of table "complications" is used at sceneTypes[1].play[1].branches[0].entry.', $thirdKey), $inUse->getMessage());
            }
        }

        $unused = ReleaseArrays::with($complications, 'entries.0.key', 'patrol-arrives');
        $gameSystem->saveOracleTable('complications', $unused, $this->now);
        self::assertSame('patrol-arrives', self::at($this->tables($gameSystem)[1], 'entries.0.key'));
    }

    #[Test]
    public function everyReferencedEntryKeyDroppedByASaveIsListed(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sceneTypes.1.play.1.branches.1', ['entry' => 'run']), $this->created);
        $complications = $this->tables($gameSystem)[1];
        /** @var list<array<string, mixed>> $entries */
        $entries = $complications['entries'];
        $dropped = [...ReleaseArrays::with($complications, 'entries.2.key', 'seen'), 'entries' => [$entries[0], $entries[1], [...$entries[2], 'key' => 'seen']]];
        $thirdKey = $entries[2]['key'];
        self::assertIsString($thirdKey);
        $droppedKeys = \sprintf('entries "%s", "run"', $thirdKey);

        try {
            $gameSystem->saveOracleTable('complications', $dropped, $this->now);
            self::fail('Expected OracleInUse');
        } catch (OracleInUse $inUse) {
            self::assertSame(['sceneTypes[1].play[1].branches[0].entry', 'sceneTypes[1].play[1].branches[1].entry'], $inUse->paths());
            self::assertSame(\sprintf('The %s of table "complications" is used at sceneTypes[1].play[1].branches[0].entry, sceneTypes[1].play[1].branches[1].entry.', $droppedKeys), $inUse->getMessage());
        }
    }

    #[Test]
    public function anUnreferencedTableCanBeDeleted(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]], $this->now);

        $gameSystem->deleteOracleTable('rumours', $this->now);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($gameSystem));
    }

    #[Test]
    public function deletingAnUnknownOracleIsNotFound(): void
    {
        $gameSystem = $this->draft();

        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteOracleTable('nothing', $this->now), OracleNotFound::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteLikelihoodOracle('nothing', $this->now), OracleNotFound::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteLikelihoodOracle('heist-scenes', $this->now), OracleNotFound::class);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidTables(): iterable
    {
        yield 'overlapping ranges' => [['key' => 'bad', 'name' => 'Bad', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 4, 'text' => 'a'], ['min' => 4, 'max' => 6, 'text' => 'b']]], 'oracles.tables'];
        yield 'an inverse range' => [['key' => 'bad', 'name' => 'Bad', 'dice' => '1d6', 'entries' => [['min' => 5, 'max' => 2, 'text' => 'a']]], 'oracles.tables'];
        yield 'unknown nested table' => [['key' => 'bad', 'name' => 'Bad', 'entries' => [['text' => 'a', 'table' => 'nowhere']]], 'oracles.tables'];
        yield 'unknown property' => [['key' => 'bad', 'name' => 'Bad', 'entries' => [['text' => 'a']], 'colour' => 'red'], 'oracles.tables[4].colour: unknown property'];
        yield 'missing entries' => [['key' => 'bad', 'name' => 'Bad'], 'oracles.tables[4].entries: required'];
        yield 'entries not a list' => [['key' => 'bad', 'name' => 'Bad', 'entries' => 'x'], 'oracles.tables[4].entries: must be a list'];
        yield 'key not a string' => [['key' => 7, 'name' => 'Bad', 'entries' => [['text' => 'a']]], 'oracles.tables'];
        yield 'invalid key' => [['key' => 'Bad Key', 'name' => 'Bad', 'entries' => [['text' => 'a']]], 'oracles.tables'];
        yield 'entry not an object' => [['key' => 'bad', 'name' => 'Bad', 'entries' => ['a']], 'oracles.tables[4].entries[0]: must be an object'];
        yield 'duplicate entry keys' => [['key' => 'bad', 'name' => 'Bad', 'entries' => [['text' => 'a', 'key' => 'same'], ['text' => 'b', 'key' => 'same']]], 'oracles.tables[4].entries[1].key: duplicate entry key "same"'];
        yield 'a blank name' => [['key' => 'bad', 'name' => '  ', 'entries' => [['text' => 'a']]], 'oracles.tables'];
        yield 'no key' => [['name' => 'Bad', 'entries' => [['text' => 'a']]], 'oracles.tables[4].key: required'];
    }

    /**
     * @param array<string, mixed> $table
     * @param non-empty-string     $expected
     */
    #[Test]
    #[DataProvider('invalidTables')]
    public function anInvalidTableIsRefusedWithItsPathAndLeavesTheDraftUnchanged(array $table, string $expected): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]], $this->created);
        $before = $gameSystem->document();

        try {
            $gameSystem->saveOracleTable(null, $table, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($expected, $invalid->getMessage());
        }

        self::assertSame($before, $gameSystem->document());
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function aLikelihoodOracleIsSavedReplacedAndDeleted(): void
    {
        $gameSystem = $this->draft();
        $fate = $this->likelihood($gameSystem)[0];
        $gameSystem->saveLikelihoodOracle(null, ['key' => 'gut-feeling', 'name' => 'Gut feeling', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], $this->now);

        $fate['name'] = 'Fate';
        $fate['exceptionalPercent'] = 10;
        $gameSystem->saveLikelihoodOracle('fate', $fate, $this->now);

        self::assertSame(['fate', 'gut-feeling'], array_column($this->likelihood($gameSystem), 'key'));
        self::assertSame('Fate', $this->likelihood($gameSystem)[0]['name']);
        self::assertSame('chaos', self::at($this->likelihood($gameSystem)[0], 'chaos.tracker'));
        ReleaseContent::fromArray($gameSystem->document());

        $gameSystem->saveLikelihoodOracle('gut-feeling', [...$this->likelihood($gameSystem)[1], 'key' => 'hunch'], $this->now);
        $gameSystem->deleteLikelihoodOracle('hunch', $this->now);
        self::assertSame(['fate'], array_column($this->likelihood($gameSystem), 'key'));
    }

    #[Test]
    public function anEntrySceneTypeIsSavedWhenTheDraftHasIt(): void
    {
        $gameSystem = $this->draft();
        $table = $this->tables($gameSystem)[1];

        $gameSystem->saveOracleTable('complications', ReleaseArrays::with($table, 'entries.2.sceneType', 'getaway'), $this->now);
        self::assertSame('getaway', self::at($this->tables($gameSystem)[1], 'entries.2.sceneType'));
    }

    #[Test]
    public function anEntryNamingAMissingSceneTypeIsRefusedWithItsPath(): void
    {
        $gameSystem = $this->draft();
        $table = ReleaseArrays::with($this->tables($gameSystem)[1], 'entries.2.sceneType', 'nowhere');

        try {
            $gameSystem->saveOracleTable('complications', $table, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertSame('oracles.tables[1].entries[2].sceneType: unknown Scene Type "nowhere"', $invalid->getMessage());
        }
    }

    #[Test]
    public function anEntryEffectNamingAMissingTrackerIsRefusedAndLeavesTheDraftUnchanged(): void
    {
        $gameSystem = $this->draft();
        $table = ReleaseArrays::with($this->tables($gameSystem)[1], 'entries.0.effects.0.tracker', 'nowhere');

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable('complications', $table, $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function aChaosTrackerMustBeACounterOfTheTrackersSection(): void
    {
        $gameSystem = $this->draft();
        $fate = $this->likelihood($gameSystem)[0];

        foreach (['alarm' => 'must be a counter, "alarm" is a clock', 'nowhere' => 'unknown tracker "nowhere"'] as $tracker => $reason) {
            try {
                $gameSystem->saveLikelihoodOracle('fate', ReleaseArrays::with($fate, 'chaos.tracker', $tracker), $this->now);
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('oracles.likelihood[0].chaos.tracker: ', $invalid->getMessage());
                self::assertStringContainsString($reason, $invalid->getMessage());
            }
        }
    }

    #[Test]
    public function aReferencedLikelihoodOracleCannotBeRenamedOrDeleted(): void
    {
        $gameSystem = $this->draft();
        $fate = $this->likelihood($gameSystem)[0];

        try {
            $gameSystem->saveLikelihoodOracle('fate', [...$fate, 'key' => 'destiny'], $this->now);
            self::fail('Expected OracleInUse');
        } catch (OracleInUse $inUse) {
            self::assertContains('sceneTypes[3].play[0]', $inUse->paths());
            self::assertContains('flows[1].oracles', $inUse->paths());
        }

        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteLikelihoodOracle('fate', $this->now), OracleInUse::class);
    }

    #[Test]
    public function aTableUsedAsARandomEventTableCannotBeDeleted(): void
    {
        $gameSystem = $this->draft('valid/v2-random-event');

        try {
            $gameSystem->deleteOracleTable('focus', $this->now);
            self::fail('Expected OracleInUse');
        } catch (OracleInUse $inUse) {
            self::assertContains('oracles.likelihood[0].randomEvent', $inUse->paths());
        }

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveLikelihoodOracle('fate', [...$this->likelihood($gameSystem)[0], 'randomEvent' => ['table' => 'nowhere']], $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function aLikelihoodOracleWithAnInvalidTargetIsRefused(): void
    {
        $gameSystem = $this->draft();
        $fate = $this->likelihood($gameSystem)[0];
        $fate = ReleaseArrays::with($fate, 'levels.0.target', 500);

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveLikelihoodOracle('fate', $fate, $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function aVersion1DraftEditsVersion1FieldsOnly(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $gameSystem->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True', 'weight' => 2]]], $this->now);
        $weather = $this->tables($gameSystem)[0];
        $weather['name'] = 'Weather today';
        $gameSystem->saveOracleTable('weather', $weather, $this->now);
        $fate = $this->likelihood($gameSystem)[0];
        $gameSystem->saveLikelihoodOracle('fate', [...$fate, 'exceptionalPercent' => 5], $this->now);

        self::assertSame(['weather', 'storm-kind', 'rumours'], $this->tableKeys($gameSystem));
        self::assertSame(1, $gameSystem->schemaVersion());
        ReleaseContent::fromArray($gameSystem->document());

        foreach ([
            fn () => $gameSystem->saveOracleTable(null, ['key' => 'more', 'name' => 'More', 'entries' => [['text' => 'a', 'key' => 'one']]], $this->now),
            fn () => $gameSystem->saveOracleTable(null, ['key' => 'more', 'name' => 'More', 'entries' => [['text' => 'a', 'sceneType' => 'x']]], $this->now),
            fn () => $gameSystem->saveOracleTable(null, ['key' => 'more', 'name' => 'More', 'entries' => [['text' => 'a', 'effects' => []]]], $this->now),
            fn () => $gameSystem->saveLikelihoodOracle('fate', ReleaseArrays::with($fate, 'chaos.tracker', 'chaos'), $this->now),
            fn () => $gameSystem->saveLikelihoodOracle('fate', [...$fate, 'randomEvent' => ['table' => 'weather']], $this->now),
        ] as $command) {
            $this->assertUntouchedAfterUpdate($gameSystem, $command);
        }
    }

    private function assertUntouchedAfterUpdate(GameSystem $gameSystem, callable $command): void
    {
        $before = $gameSystem->document();
        try {
            $command();
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringContainsString('unknown property', $invalid->getMessage());
        }

        self::assertSame($before, $gameSystem->document());
    }

    #[Test]
    public function aMalformedStoredSectionRefusesTheCommandWithoutAnError500(): void
    {
        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), 'my-copy', 'My copy', null, 'user-1', ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal'))->toArray(), $this->created);
        $gameSystem->restore([...$gameSystem->document(), 'oracles' => 'broken'], $this->created);

        $this->assertUntouched($gameSystem, fn () => $gameSystem->saveOracleTable(null, ['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'x']]], $this->now), InvalidReleaseContent::class);
        $this->assertUntouched($gameSystem, fn () => $gameSystem->deleteOracleTable('a', $this->now), InvalidReleaseContent::class);
    }
}
