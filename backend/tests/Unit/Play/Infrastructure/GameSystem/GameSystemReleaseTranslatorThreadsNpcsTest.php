<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\CloseThreadEffect;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\CreateNpcEffect;
use App\Play\Domain\GameSystem\Flow\CreateThreadEffect;
use App\Play\Domain\GameSystem\Flow\ItemCount;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\SceneTitleEffect;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the Threads and NPCs parts of schema version 2: tags, the
 * pick step, count conditions and the NPC / Thread Effects. It fails closed on anything Studio
 * would have rejected, and v2 releases without these parts read as before.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(PickStep::class)]
#[CoversClass(ConditionStep::class)]
final class GameSystemReleaseTranslatorThreadsNpcsTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-threads-npcs';

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    private function step(string $key, ?GameSystemSnapshot $snapshot = null): Step
    {
        $snapshot ??= $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        return $snapshot->sceneType('scene')?->play->step($key) ?? self::fail(\sprintf('No step "%s".', $key));
    }

    #[Test]
    public function itMapsTags(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        self::assertEquals([
            new Tag('leader', 'Leader', 'Runs a faction or a crew.'),
            new Tag('ally', 'Ally'),
            new Tag('hostile', 'Hostile', 'Works against the player.'),
        ], $snapshot->tags());
        self::assertEquals(new Tag('ally', 'Ally'), $snapshot->tag('ally'));
        self::assertNull($snapshot->tag('ghost'));
    }

    #[Test]
    public function aTagReadsItsScopeAndAnUnscopedOneAppliesToEveryKind(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent(self::FIXTURE), 'tags.2.appliesTo', ['npc', 'thread']);
        $content = ReleaseArrays::with($content, 'tags.1.appliesTo', null);

        $tags = $this->translate($content)->tags();

        self::assertEquals(new Tag('hostile', 'Hostile', 'Works against the player.', [ItemKind::Npc, ItemKind::Thread]), $tags[2]);
        self::assertSame([ItemKind::Npc, ItemKind::Thread], $tags[2]->appliesTo);
        self::assertSame([[], [true, true, true, true], [true, true, false, false]], [
            $tags[0]->appliesTo,
            array_map($tags[1]->isFor(...), ItemKind::cases()),
            array_map($tags[2]->isFor(...), ItemKind::cases()),
        ]);
    }

    #[Test]
    public function itMapsAPickStepWithEveryField(): void
    {
        self::assertEquals(
            new PickStep(
                'pick-rival', 'Who stirs trouble?', null, null, false, null, [],
                ItemKind::Npc, ['leader', 'hostile'], PickMode::Random, 1, 2,
                new Outcome(effects: [new CreateThreadEffect('Hunt for {picked}', ['hostile'])]),
                new Outcome('watch'),
            ),
            $this->step('pick-rival'),
        );
    }

    #[Test]
    public function aPickStepDefaultsToOneItemAndMissingBranches(): void
    {
        $step = $this->step('pick-ally');

        self::assertEquals(
            new PickStep('pick-ally', 'Who helps?', 'Choose who stands with you, if {picked} allows.', null, true, null, [], ItemKind::Npc, ['ally'], PickMode::Choose, 1, 1, new Outcome(effects: [new CloseThreadEffect()])),
            $step,
        );
    }

    #[Test]
    public function aMinimalPickStepNeedsNoTagsCountOrBranches(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent(self::FIXTURE), 'sceneTypes.0.play', [
            ['key' => 'any', 'kind' => 'pick', 'title' => 'Anyone?', 'of' => 'thread', 'mode' => 'choose', 'tags' => null, 'count' => null, 'branches' => null],
        ]);

        self::assertEquals(
            new PickStep('any', 'Anyone?', null, null, false, null, [], ItemKind::Thread, [], PickMode::Choose),
            $this->step('any', $this->translate($content)),
        );
    }

    #[Test]
    public function itMapsCountConditions(): void
    {
        self::assertEquals(
            new ConditionStep('watch', 'How many open Threads?', null, null, false, null, [], null, [
                new Band(0, new Outcome('end')),
                new Band(3, new Outcome()),
                new Band(null, new Outcome()),
            ], new ItemCount(ItemKind::Thread)),
            $this->step('watch'),
        );

        $crowd = $this->step('crowd');
        self::assertInstanceOf(ConditionStep::class, $crowd);
        self::assertEquals(new ItemCount(ItemKind::Npc, ['leader']), $crowd->count);
        self::assertNull($crowd->tracker);
        self::assertEquals(new Outcome(effects: [new CreateNpcEffect('Rival of {picked}', 'Wants {picked} gone.', ['hostile', 'leader'])]), $crowd->bands[1]->outcome);
    }

    #[Test]
    public function itMapsTheThreeEffects(): void
    {
        self::assertEquals([
            new CreateNpcEffect(),
            new CreateNpcEffect('Stranger', 'Keeps secrets.'),
            new CreateThreadEffect(),
            new CreateThreadEffect('Find {picked}'),
            new CloseThreadEffect(),
            new SceneTitleEffect('Meeting {picked}'),
        ], $this->step('meet')->effects);
    }

    #[Test]
    public function itMapsTheEffectsOfATableEntry(): void
    {
        $entries = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        self::assertEquals(
            [new CreateNpcEffect('Rival of {picked}', 'Wants {picked} gone.', ['hostile']), new CreateThreadEffect('Settle the score', ['hostile'])],
            $this->rolledEffects($entries, 0),
        );
        self::assertEquals([new CloseThreadEffect()], $this->rolledEffects($entries, 1));
    }

    /**
     * @return list<object>
     */
    private function rolledEffects(GameSystemSnapshot $snapshot, int $entry): array
    {
        $step = new OracleTableStep('encounters', 'Encounters', '1d2', $entry + 1, 'x', null);

        return $snapshot->rolledEntry($step)->effects ?? self::fail('No entry metadata.');
    }

    #[Test]
    public function aVersion2ReleaseWithoutTheNewPartsReadsAsBefore(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent('valid/v2-every-part'));

        self::assertSame([], $snapshot->tags());
        $conditions = array_filter(
            array_merge(...array_map(static fn (Phase $phase): array => $phase->worldTurn->steps, $snapshot->flow('heist')->phases ?? [])),
            static fn (Step $step): bool => $step instanceof ConditionStep,
        );
        self::assertNotSame([], $conditions);
        foreach ($conditions as $condition) {
            self::assertNotNull($condition->tracker);
            self::assertNull($condition->count);
        }
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedParts(): iterable
    {
        $pick = 'sceneTypes.0.play.0';
        $pickPath = 'sceneTypes[0].play[0]';
        yield 'tags' => ['tags', 'leader', 'tags: must be a list.'];
        yield 'tag label' => ['tags.0.label', 5, 'tags[0].label: must be a string.'];
        yield 'tag scope' => ['tags.0.appliesTo', 'npc', 'tags[0].appliesTo: must be a list.'];
        yield 'tag scope kind' => ['tags.0.appliesTo', ['npc', 'faction'], 'tags[0].appliesTo[1]: unknown entity kind "faction".'];
        yield 'empty tag scope' => ['tags.0.appliesTo', [], 'tags[0].appliesTo: must not be empty.'];
        yield 'duplicate tag key' => ['tags.1.key', 'leader', 'tags[1].key: duplicate key "leader".'];
        yield 'pick item kind' => [$pick.'.of', 'monster', $pickPath.'.of: unknown item kind "monster".'];
        yield 'pick mode' => [$pick.'.mode', 'dice', $pickPath.'.mode: unknown pick mode "dice".'];
        yield 'pick tags' => [$pick.'.tags', 'leader', $pickPath.'.tags: must be a list.'];
        yield 'pick tag' => [$pick.'.tags', ['leader', 'ghost'], $pickPath.'.tags[1]: unknown tag "ghost".'];
        yield 'pick branch tag' => [$pick.'.branches.found.effects.0.tags', ['ghost'], $pickPath.'.branches.found.effects[0].tags[0]: unknown tag "ghost".'];
        yield 'pick count min type' => [$pick.'.count.min', 'one', $pickPath.'.count.min: must be an integer.'];
        yield 'pick count max missing' => [$pick.'.count', ['min' => 1], $pickPath.'.count.max: must be an integer.'];
        yield 'pick count min below 1' => [$pick.'.count', ['min' => 0, 'max' => 2], $pickPath.'.count: must satisfy 1 <= min <= max <= 10.'];
        yield 'pick count max below min' => [$pick.'.count', ['min' => 3, 'max' => 2], $pickPath.'.count: must satisfy 1 <= min <= max <= 10.'];
        yield 'pick count max above 10' => [$pick.'.count', ['min' => 1, 'max' => 11], $pickPath.'.count: must satisfy 1 <= min <= max <= 10.'];
        yield 'count item kind' => ['sceneTypes.0.play.2.count.of', 'faction', 'sceneTypes[0].play[2].count.of: unknown item kind "faction".'];
        yield 'count tag' => ['sceneTypes.0.play.3.count.tags', ['ghost'], 'sceneTypes[0].play[3].count.tags[0]: unknown tag "ghost".'];
        yield 'tracker and count' => ['sceneTypes.0.play.2.tracker', 'alarm', 'sceneTypes[0].play[2]: must set exactly one of tracker and count.'];
        yield 'neither tracker nor count' => ['sceneTypes.0.play.2.count', null, 'sceneTypes[0].play[2]: must set exactly one of tracker and count.'];
        yield 'effect name' => ['sceneTypes.0.play.4.effects.1.name', 5, 'sceneTypes[0].play[4].effects[1].name: must be a string.'];
        yield 'effect tag' => ['sceneTypes.0.play.4.effects.0.tags', ['ghost'], 'sceneTypes[0].play[4].effects[0].tags[0]: unknown tag "ghost".'];
        yield 'band effect tag' => ['sceneTypes.0.play.3.bands.1.effects.0.tags', ['ghost'], 'sceneTypes[0].play[3].bands[1].effects[0].tags[0]: unknown tag "ghost".'];
        yield 'table entry effect tag' => ['oracles.tables.0.entries.0.effects.1.tags', ['ghost'], 'oracles.tables[0].entries[0].effects[1].tags[0]: unknown tag "ghost".'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function malformedPartsAreAPlayError(string $path, mixed $value, string $expectedMessage): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent(self::FIXTURE), $path, $value);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('cannot be read by Play: '.$expectedMessage);

        $this->translate($content);
    }

    #[Test]
    public function aTagIsUnknownWhenTheReleaseHasNoTags(): void
    {
        $content = ReleaseArrays::without(ReleaseViews::fixtureContent(self::FIXTURE), 'tags');

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('oracles.tables[0].entries[0].effects[0].tags[0]: unknown tag "hostile".');

        $this->translate($content);
    }

    #[Test]
    public function aConditionStepNeedsExactlyOneOfTrackerAndCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConditionStep('both', 'Both?', null, null, false, null, [], 'heat', [], new ItemCount(ItemKind::Npc));
    }

    #[Test]
    public function aConditionStepWithNeitherIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConditionStep('neither', 'Neither?', null, null, false, null, [], null, []);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidCounts(): iterable
    {
        yield 'min below 1' => [0, 1];
        yield 'max below min' => [2, 1];
        yield 'max above 10' => [1, 11];
    }

    #[Test]
    #[DataProvider('invalidCounts')]
    public function aPickStepRejectsAnInvalidCount(int $min, int $max): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PickStep('pick', 'Pick', null, null, false, null, [], ItemKind::Npc, [], PickMode::Random, $min, $max);
    }
}
