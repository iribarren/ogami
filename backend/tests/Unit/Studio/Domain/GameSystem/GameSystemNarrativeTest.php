<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\NarrativeItemNotFound;
use App\Studio\Domain\GameSystem\NarrativeKeyTaken;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against valid/v2-every-part.json: trackers alarm, edge, chaos, heat; no tags; fact slots
 * target, client, ambition (unreferenced); Scene Types legwork, infiltration, firefight, getaway,
 * downtime (unreferenced); flows heist (default) and quick.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(NarrativeSection::class)]
#[CoversClass(NarrativeItemInUse::class)]
#[CoversClass(NarrativeItemNotFound::class)]
#[CoversClass(NarrativeKeyTaken::class)]
final class GameSystemNarrativeTest extends TestCase
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
     * @return list<string>
     */
    private function keys(GameSystem $gameSystem, NarrativeSection $section): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $gameSystem->document()[$section->value] ?? [];

        /** @var list<string> $keys */
        $keys = array_column($items, 'key');

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(GameSystem $gameSystem, NarrativeSection $section, string $key): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $gameSystem->document()[$section->value];
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        throw new \LogicException('No item '.$key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tracker(string $key = 'noise'): array
    {
        return ['key' => $key, 'name' => 'Noise', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tag(string $key = 'gang'): array
    {
        return ['key' => $key, 'label' => 'Gang'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function factSlot(string $key = 'rival'): array
    {
        return ['key' => $key, 'label' => 'Rival', 'type' => 'npc'];
    }

    /**
     * @param list<array<string, mixed>> $play
     *
     * @return array<string, mixed>
     */
    private static function sceneType(string $key = 'rest', array $play = []): array
    {
        return ['key' => $key, 'name' => 'Rest', 'purpose' => 'Catch your breath.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flow(string $key = 'night', bool $default = false): array
    {
        return [
            'key' => $key, 'name' => 'Night', 'defaultView' => 'focus', 'oracles' => [], 'trackers' => [],
            ...$default ? ['default' => true] : [],
            'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['legwork']]]],
        ];
    }

    /**
     * A step of a Scene Type carrying one effect.
     *
     * @param array<string, mixed> $effect
     *
     * @return array<string, mixed>
     */
    private function stepWith(array $effect, string $key = 'beat'): array
    {
        return ['key' => $key, 'kind' => 'prompt', 'title' => 'Beat', 'effects' => [$effect]];
    }

    /**
     * @return iterable<string, array{NarrativeSection, array<string, mixed>}>
     */
    public static function newItems(): iterable
    {
        yield 'tracker' => [NarrativeSection::Trackers, self::tracker()];
        yield 'tag' => [NarrativeSection::Tags, self::tag()];
        yield 'fact slot' => [NarrativeSection::FactSlots, self::factSlot()];
        yield 'Scene Type' => [NarrativeSection::SceneTypes, self::sceneType()];
        yield 'flow' => [NarrativeSection::Flows, self::flow()];
    }

    /**
     * @param array<string, mixed> $item
     */
    #[Test]
    #[DataProvider('newItems')]
    public function aNewItemIsAppendedAsGivenAndUpdatesTheDraft(NarrativeSection $section, array $item): void
    {
        $gameSystem = $this->draft();
        $count = \count($this->keys($gameSystem, $section));

        $gameSystem->saveNarrativeItem($section, null, $item, $this->now);

        $keys = $this->keys($gameSystem, $section);
        self::assertCount($count + 1, $keys);
        self::assertSame($item['key'], $keys[$count]);
        self::assertSame($item, $this->item($gameSystem, $section, (string) $item['key']));
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    /**
     * @param array<string, mixed> $item
     */
    #[Test]
    #[DataProvider('newItems')]
    public function aReplacedItemKeepsItsPositionAndARenameToo(NarrativeSection $section, array $item): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveNarrativeItem($section, null, $item, $this->now);
        $gameSystem->saveNarrativeItem($section, null, [...$item, 'key' => 'other'], $this->now);
        $before = $this->keys($gameSystem, $section);
        /** @var string $original */
        $original = $item['key'];
        $position = array_search($original, $before, true);

        $gameSystem->saveNarrativeItem($section, $original, [...$item, 'key' => 'renamed'], $this->now);

        $after = $this->keys($gameSystem, $section);
        self::assertSame($position, array_search('renamed', $after, true));
        self::assertSame(array_diff_key($before, [$position => 1]), array_diff_key($after, [$position => 1]));
    }

    #[Test]
    public function aReplacementWithTheSameKeyChangesOnlyThatItem(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        $edge = $this->item($gameSystem, NarrativeSection::Trackers, 'edge');

        $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, 'edge', [...$edge, 'name' => 'Advantage'], $this->now);

        self::assertSame(ReleaseArrays::with($document, 'trackers.1.name', 'Advantage'), $gameSystem->document());
    }

    #[Test]
    public function aMissingOptionalSectionIsAnEmptyList(): void
    {
        $gameSystem = $this->draft();
        self::assertArrayNotHasKey('tags', $gameSystem->document());

        $gameSystem->saveNarrativeItem(NarrativeSection::Tags, null, self::tag(), $this->now);

        self::assertSame(['gang'], $this->keys($gameSystem, NarrativeSection::Tags));
    }

    #[Test]
    public function anUnreferencedItemOfEverySectionCanBeRenamedAndDeleted(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, null, self::tracker(), $this->now);
        $gameSystem->saveNarrativeItem(NarrativeSection::Tags, null, self::tag(), $this->now);

        $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, 'noise', self::tracker('din'), $this->now);
        $gameSystem->saveNarrativeItem(NarrativeSection::Tags, 'gang', self::tag('crew'), $this->now);
        $gameSystem->saveNarrativeItem(NarrativeSection::FactSlots, 'target', [...$this->item($gameSystem, NarrativeSection::FactSlots, 'target'), 'key' => 'mark'], $this->now);
        $gameSystem->saveNarrativeItem(NarrativeSection::SceneTypes, 'downtime', self::sceneType('rest'), $this->now);
        foreach ([[NarrativeSection::Trackers, 'din'], [NarrativeSection::Tags, 'crew'], [NarrativeSection::FactSlots, 'mark'], [NarrativeSection::SceneTypes, 'rest']] as [$section, $key]) {
            $gameSystem->deleteNarrativeItem($section, $key, $this->now);
            self::assertNotContains($key, $this->keys($gameSystem, $section));
        }

        self::assertSame(['alarm', 'edge', 'chaos', 'heat'], $this->keys($gameSystem, NarrativeSection::Trackers));
        self::assertSame([], $this->keys($gameSystem, NarrativeSection::Tags));
        self::assertSame(['client', 'ambition'], $this->keys($gameSystem, NarrativeSection::FactSlots));
        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway'], $this->keys($gameSystem, NarrativeSection::SceneTypes));
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function aFlowIsRenamedAndDeletedFreely(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveNarrativeItem(NarrativeSection::Flows, 'heist', [...$this->item($gameSystem, NarrativeSection::Flows, 'heist'), 'key' => 'job'], $this->now);
        self::assertSame(['job', 'quick'], $this->keys($gameSystem, NarrativeSection::Flows));

        $gameSystem->deleteNarrativeItem(NarrativeSection::Flows, 'job', $this->now);
        $gameSystem->deleteNarrativeItem(NarrativeSection::Flows, 'quick', $this->now);
        self::assertSame([], $this->keys($gameSystem, NarrativeSection::Flows));
    }

    #[Test]
    public function savingADefaultFlowClearsTheFlagOnTheOthers(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveNarrativeItem(NarrativeSection::Flows, null, self::flow('night', default: true), $this->now);
        self::assertSame([null, null, true], $this->defaults($gameSystem));

        $quick = $this->item($gameSystem, NarrativeSection::Flows, 'quick');
        $gameSystem->saveNarrativeItem(NarrativeSection::Flows, 'quick', [...$quick, 'default' => true], $this->now);

        self::assertSame(['heist', 'quick', 'night'], $this->keys($gameSystem, NarrativeSection::Flows));
        self::assertSame([null, true, null], $this->defaults($gameSystem));
    }

    /**
     * @return list<bool|null>
     */
    private function defaults(GameSystem $gameSystem): array
    {
        /** @var list<array<string, mixed>> $flows */
        $flows = $gameSystem->document()['flows'];

        /** @var list<bool|null> $defaults */
        $defaults = array_map(static fn (array $flow): mixed => $flow['default'] ?? null, $flows);

        return $defaults;
    }

    #[Test]
    public function aTakenKeyIsRefusedOnCreateAndOnRenameButOtherSectionsMayShareIt(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([null, 'edge'] as $original) {
            try {
                $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, $original, self::tracker('alarm'), $this->now);
                self::fail('Expected NarrativeKeyTaken');
            } catch (NarrativeKeyTaken $taken) {
                self::assertSame('The draft already has a tracker "alarm".', $taken->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());

        $gameSystem->saveNarrativeItem(NarrativeSection::Tags, null, self::tag('alarm'), $this->now);
        self::assertSame(['alarm'], $this->keys($gameSystem, NarrativeSection::Tags));
    }

    #[Test]
    public function anUnknownItemIsNotFound(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, 'nowhere', self::tracker(), $this->now),
            fn () => $gameSystem->deleteNarrativeItem(NarrativeSection::SceneTypes, 'nowhere', $this->now),
            fn () => $gameSystem->deleteNarrativeItem(NarrativeSection::Tags, 'gang', $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected NarrativeItemNotFound');
            } catch (NarrativeItemNotFound) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame($document, $gameSystem->document());
    }

    /**
     * @return iterable<string, array{NarrativeSection, string, list<string>}>
     */
    public static function referenced(): iterable
    {
        yield 'tracker' => [NarrativeSection::Trackers, 'chaos', ['flows[0].trackers', 'oracles.likelihood[0].chaos']];
        yield 'Scene Type' => [NarrativeSection::SceneTypes, 'getaway', ['flows[0].phases[2].selection', 'oracles.tables[1].entries[3]']];
    }

    /**
     * @param list<string> $paths
     */
    #[Test]
    #[DataProvider('referenced')]
    public function aReferencedItemCannotBeRenamedOrDeleted(NarrativeSection $section, string $key, array $paths): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        $renamed = [...$this->item($gameSystem, $section, $key), 'key' => 'renamed'];

        foreach ([
            fn () => $gameSystem->saveNarrativeItem($section, $key, $renamed, $this->now),
            fn () => $gameSystem->deleteNarrativeItem($section, $key, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected NarrativeItemInUse');
            } catch (NarrativeItemInUse $inUse) {
                self::assertSame($paths, $inUse->paths());
                self::assertSame(\sprintf('The %s "%s" is used at %s.', $section->noun(), $key, implode(', ', $paths)), $inUse->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function aTagAndAFactSlotInUseCannotBeRenamedOrDeleted(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveNarrativeItem(NarrativeSection::Tags, null, self::tag(), $this->now);
        $effects = [['kind' => 'createNpc', 'name' => 'Boss', 'tags' => ['gang']], ['kind' => 'fillFact', 'slot' => 'target', 'text' => 'The vault']];
        $gameSystem->saveNarrativeItem(NarrativeSection::SceneTypes, null, self::sceneType('meeting', [$this->stepWith($effects[0], 'npc'), $this->stepWith($effects[1], 'fact')]), $this->now);

        foreach ([[NarrativeSection::Tags, 'gang', 'sceneTypes[5].play[0].effects[0]'], [NarrativeSection::FactSlots, 'target', 'sceneTypes[5].play[1].effects[0]']] as [$section, $key, $path]) {
            foreach ([
                fn () => $gameSystem->saveNarrativeItem($section, $key, [...$this->item($gameSystem, $section, $key), 'key' => 'renamed'], $this->now),
                fn () => $gameSystem->deleteNarrativeItem($section, $key, $this->now),
            ] as $command) {
                try {
                    $command();
                    self::fail('Expected NarrativeItemInUse');
                } catch (NarrativeItemInUse $inUse) {
                    self::assertSame([$path], $inUse->paths());
                }
            }
        }
    }

    #[Test]
    public function aSceneTypeReferencingItselfCanBeDeletedButNotRenamed(): void
    {
        $gameSystem = $this->draft();
        $loop = self::sceneType('loop', [$this->stepWith(['kind' => 'nextScene', 'sceneType' => 'loop'])]);
        $gameSystem->saveNarrativeItem(NarrativeSection::SceneTypes, null, $loop, $this->now);

        try {
            $gameSystem->saveNarrativeItem(NarrativeSection::SceneTypes, 'loop', [...$loop, 'key' => 'again'], $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sceneTypes[5].play[0].effects[0].sceneType: ', $invalid->getMessage());
        }

        $gameSystem->deleteNarrativeItem(NarrativeSection::SceneTypes, 'loop', $this->now);

        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway', 'downtime'], $this->keys($gameSystem, NarrativeSection::SceneTypes));
    }

    /**
     * @return iterable<string, array{NarrativeSection, array<array-key, mixed>, string}>
     */
    public static function invalidItems(): iterable
    {
        yield 'tracker with min above max' => [NarrativeSection::Trackers, [...self::tracker(), 'min' => 9], 'trackers[4]'];
        yield 'tracker without a name' => [NarrativeSection::Trackers, ['key' => 'noise', 'kind' => 'clock', 'segments' => 4], 'trackers[4]'];
        yield 'tag key' => [NarrativeSection::Tags, self::tag('Not A Key'), 'tags[0].key'];
        yield 'fact slot type' => [NarrativeSection::FactSlots, [...self::factSlot(), 'type' => 'vehicle'], 'factSlots[3].type'];
        yield 'Scene Type naming a missing oracle' => [NarrativeSection::SceneTypes, [...self::sceneType(), 'oracles' => ['ghost']], 'sceneTypes[5].oracles[0]'];
        yield 'flow selecting a missing Scene Type' => [NarrativeSection::Flows, [...self::flow(), 'trackers' => ['ghost']], 'flows[2].trackers[0]'];
        yield 'item that is not an object' => [NarrativeSection::Tags, ['a', 'b'], 'tags[0]'];
    }

    /**
     * @param array<array-key, mixed> $item
     * @param non-empty-string        $path
     */
    #[Test]
    #[DataProvider('invalidItems')]
    public function anInvalidItemIsRefusedWithItsPathAndNothingChanges(NarrativeSection $section, array $item, string $path): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveNarrativeItem($section, null, $item, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($path, $invalid->getMessage());
        }

        self::assertSame($document, $gameSystem->document());
        self::assertSame(1, $gameSystem->version());
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function aChangeThatBreaksAReferrerIsRefusedWithTheReferrersPath(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        // The "chaos" counter is the chaos factor of the likelihood oracle "fate": a clock cannot be.
        $clock = ['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'clock', 'segments' => 9];

        try {
            $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, 'chaos', $clock, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('oracles.likelihood[0].chaos.tracker: must be a counter', $invalid->getMessage());
        }

        self::assertSame($document, $gameSystem->document());
    }

    #[Test]
    public function aLegacyDraftBreakingTheItemRulesElsewhereIsRefusedWithThatPath(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sceneTypes.0.oracles', ['ghost']), $this->created);
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveNarrativeItem(NarrativeSection::Tags, null, self::tag(), $this->now),
            fn () => $gameSystem->deleteNarrativeItem(NarrativeSection::FactSlots, 'target', $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('sceneTypes[0].oracles[0]: ', $invalid->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
    }

    #[Test]
    public function aSectionThatIsNotAListIsRefusedAtItsPath(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with(ReleaseArrays::with($gameSystem->document(), 'tags', 'many'), 'flows', ['a' => []]), $this->created);

        foreach ([[NarrativeSection::Tags, 'tags'], [NarrativeSection::Flows, 'flows']] as [$section, $path]) {
            try {
                $gameSystem->saveNarrativeItem($section, null, self::tag(), $this->now);
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertSame($path.': must be a list', $invalid->getMessage());
            }
        }
    }

    #[Test]
    public function aSchemaVersion1DraftHasNoNarrativeSections(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveNarrativeItem(NarrativeSection::Trackers, null, self::tracker(), $this->now),
            fn () => $gameSystem->deleteNarrativeItem(NarrativeSection::Flows, 'heist', $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertSame('schemaVersion: narrative sections need schema version 2, this draft is version 1', $invalid->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
    }
}
