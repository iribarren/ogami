<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\AuthoringWarnings;
use App\Studio\Domain\Release\Version2\Catalog;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\Placeholders;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\StepParts;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts feature 8 adds to schema version 2: tags, the pick step, condition counts, the NPC and
 * Thread effects and {picked}. Run against valid/v2-threads-npcs.json. Its indexes: tags leader
 * (0), ally (1), hostile (2); table encounters (0: rival, cold); Scene Type scene (0) with play
 * steps pick-rival (0), pick-ally (1), watch (2, count of threads), crowd (3, count of leaders)
 * and meet (4, prompt with every new effect).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Steps::class)]
#[CoversClass(StepParts::class)]
#[CoversClass(Effects::class)]
#[CoversClass(Placeholders::class)]
#[CoversClass(AuthoringWarnings::class)]
final class ReleaseContentThreadsNpcsTest extends TestCase
{
    private const string PICK = 'sceneTypes.0.play.0';
    private const string PICK_PATH = 'sceneTypes[0].play[0]';
    private const string MEET = 'sceneTypes.0.play.4';

    /**
     * @return array<mixed>
     */
    private static function release(?string $path = null, mixed $value = null): array
    {
        $release = ReleaseArrays::fixture('valid/v2-threads-npcs');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<string, mixed>
     */
    private function canonicalStep(array $release, int $index): array
    {
        /** @var list<array{play: list<array<string, mixed>>}> $sceneTypes */
        $sceneTypes = ReleaseContent::fromArray($release)->toArray()['sceneTypes'];

        return $sceneTypes[0]['play'][$index];
    }

    #[Test]
    public function itAcceptsEveryNewPartAndKeepsItsCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $canonical = $content->toArray();

        self::assertSame([], $content->warnings());
        self::assertSame(['schemaVersion', 'gameSystem', 'oracles', 'trackers', 'factSlots', 'tags', 'sceneTypes', 'flows', 'sheet', 'checks'], array_keys($canonical));
        self::assertSame([['key' => 'leader', 'label' => 'Leader', 'hint' => 'Runs a faction or a crew.'], ['key' => 'ally', 'label' => 'Ally'], ['key' => 'hostile', 'label' => 'Hostile', 'hint' => 'Works against the player.']], $canonical['tags'] ?? null);
        self::assertSame(['key', 'kind', 'title', 'of', 'tags', 'mode', 'count', 'branches'], array_keys($this->canonicalStep(self::release(), 0)));
        self::assertSame(['key', 'kind', 'title', 'count', 'bands'], array_keys($this->canonicalStep(self::release(), 2)));
        self::assertSame(ReleaseContent::fromArray($canonical)->hash(), $content->hash());
    }

    #[Test]
    public function itOmitsEmptyTagListsAndTheDefaultCount(): void
    {
        $release = self::release('tags', []);
        $release = ReleaseArrays::with($release, 'oracles.tables', []);
        $release = ReleaseArrays::with($release, 'sceneTypes.0.play', [
            ['key' => 'one', 'kind' => 'pick', 'title' => 'One', 'of' => 'npc', 'mode' => 'choose', 'tags' => [], 'count' => ['min' => 1, 'max' => 1]],
            ['key' => 'two', 'kind' => 'pick', 'title' => 'Two', 'of' => 'thread', 'mode' => 'random', 'count' => ['min' => 1, 'max' => 2]],
            ['key' => 'three', 'kind' => 'condition', 'title' => 'Three', 'count' => ['of' => 'npc', 'tags' => []], 'bands' => [[]]],
            ['key' => 'four', 'kind' => 'prompt', 'title' => 'Four', 'effects' => [['kind' => 'createNpc', 'tags' => []], ['kind' => 'createThread', 'name' => null, 'tags' => []]]],
        ]);

        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertArrayNotHasKey('tags', $content);
        self::assertSame(['key' => 'one', 'kind' => 'pick', 'title' => 'One', 'of' => 'npc', 'mode' => 'choose'], $this->canonicalStep($release, 0));
        self::assertSame(['min' => 1, 'max' => 2], $this->canonicalStep($release, 1)['count']);
        self::assertSame(['of' => 'npc'], $this->canonicalStep($release, 2)['count']);
        self::assertSame([['kind' => 'createNpc'], ['kind' => 'createThread']], $this->canonicalStep($release, 3)['effects']);
    }

    #[Test]
    public function aCountConditionNeverReadsAsATrackerReference(): void
    {
        $flow = [
            'key' => 'main', 'name' => 'Main', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [],
            'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]]],
        ];
        $release = self::release('flows', [$flow]);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
        self::assertSame([], [...StepParts::trackers($this->canonicalStep($release, 2), 'step')]);
    }

    #[Test]
    public function aCountConditionDecidingANextSceneWarnsNothing(): void
    {
        $forced = [['kind' => 'nextScene', 'sceneType' => 'scene']];
        $release = self::release('sceneTypes.0.play.3.bands', [['upTo' => 1, 'effects' => $forced], []]);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function pickedPlaceholders(): iterable
    {
        yield 'step title' => [self::PICK.'.title', 'Hunting {picked}'];
        yield 'step prompt' => [self::PICK.'.prompt', 'Where is {picked}?'];
        yield 'step tip' => [self::PICK.'.tip', '{picked}, {picked}'];
        yield 'sceneTitle title' => [self::MEET.'.effects.5.title', 'Meeting {picked}'];
        yield 'createNpc name' => [self::MEET.'.effects.1.name', '{picked} Junior'];
        yield 'createNpc agenda' => [self::MEET.'.effects.1.agenda', 'Avenge {picked}.'];
        yield 'createThread name' => [self::MEET.'.effects.3.name', 'Find {picked}'];
        yield 'table entry effect' => ['oracles.tables.0.entries.0.effects.1.name', 'Avenge {picked}'];
    }

    #[Test]
    #[DataProvider('pickedPlaceholders')]
    public function pickedIsAllowedInAnyPlaceholderText(string $path, string $text): void
    {
        self::assertSame([], ReleaseContent::fromArray(self::release($path, $text))->warnings());
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidReleases(): iterable
    {
        // Tags.
        yield 'tag key is a number' => [self::release('tags.0.key', 3), 'tags[0].key: must be a string'];
        yield 'tag is a string' => [self::release('tags.0', 'leader'), 'tags[0]: must be an object'];
        yield 'tag label is blank' => [self::release('tags.0.label', '  '), 'tags[0].label: must not be blank'];
        yield 'tag label too long' => [self::release('tags.0.label', str_repeat('l', 51)), 'tags[0].label: must be at most 50 characters'];
        yield 'tag hint is a number' => [self::release('tags.0.hint', 3), 'tags[0].hint: must be a string'];
        yield 'unknown tag property' => [self::release('tags.0.color', 'red'), 'tags[0].color: unknown property'];
        yield 'duplicate tag key' => [self::release('tags.2.key', 'leader'), 'tags[2].key: duplicate tag key "leader"'];

        // The pick step.
        yield 'pick without of' => [ReleaseArrays::without(self::release(), self::PICK.'.of'), self::PICK_PATH.'.of: required'];
        yield 'pick without mode' => [ReleaseArrays::without(self::release(), self::PICK.'.mode'), self::PICK_PATH.'.mode: required'];
        yield 'pick tags is a string' => [self::release(self::PICK.'.tags', 'ally'), self::PICK_PATH.'.tags: must be a list'];
        yield 'pick tag is a number' => [self::release(self::PICK.'.tags.0', 3), self::PICK_PATH.'.tags[0]: must be a string'];
        yield 'pick tag is not a key' => [self::release(self::PICK.'.tags.0', 'Not A Key'), self::PICK_PATH.'.tags[0]: must be 1 to 64 characters'];
        yield 'pick unknown tag' => [self::release(self::PICK.'.tags.1', 'ghost'), self::PICK_PATH.'.tags[1]: unknown tag "ghost"'];
        yield 'pick duplicate tag' => [self::release(self::PICK.'.tags.1', 'leader'), self::PICK_PATH.'.tags[1]: duplicate tag "leader"'];
        yield 'pick count is a list' => [self::release(self::PICK.'.count', [1, 2]), self::PICK_PATH.'.count: must be an object'];
        yield 'pick count without max' => [self::release(self::PICK.'.count', ['min' => 1]), self::PICK_PATH.'.count.max: required'];
        yield 'pick count max is a float' => [self::release(self::PICK.'.count.max', 2.5), self::PICK_PATH.'.count.max: must be an integer'];
        yield 'pick count min above max' => [self::release(self::PICK.'.count', ['min' => 4, 'max' => 3]), self::PICK_PATH.'.count.max: must be at least min (4), 3 given'];
        yield 'pick count max above ten' => [self::release(self::PICK.'.count.max', 11), self::PICK_PATH.'.count.max: must be at most 10, 11 given'];
        yield 'pick branches is a list' => [self::release(self::PICK.'.branches', ['found']), self::PICK_PATH.'.branches: must be an object'];
        yield 'pick unknown branch' => [self::release(self::PICK.'.branches.maybe', []), self::PICK_PATH.'.branches.maybe: unknown property'];
        yield 'pick branch effect with an unknown tag' => [self::release(self::PICK.'.branches.found.effects.0.tags.0', 'ghost'), self::PICK_PATH.'.branches.found.effects[0].tags[0]: unknown tag "ghost"'];
        yield 'pick none branch going backward' => [self::release(self::PICK.'.branches.none.next', 'pick-rival'), self::PICK_PATH.'.branches.none.next: must name a later step'];

        // Condition counts.
        yield 'condition with neither' => [ReleaseArrays::without(self::release(), 'sceneTypes.0.play.2.count'), 'sceneTypes[0].play[2]: requires tracker or count'];
        yield 'condition with both' => [self::release('sceneTypes.0.play.2.tracker', 'alarm'), 'sceneTypes[0].play[2]: give either tracker or count, not both'];
        yield 'condition count is a string' => [self::release('sceneTypes.0.play.2.count', 'npc'), 'sceneTypes[0].play[2].count: must be an object'];
        yield 'condition count of is unknown' => [self::release('sceneTypes.0.play.2.count.of', 'faction'), 'sceneTypes[0].play[2].count.of: must be one of "npc", "thread", "place", "character", \'faction\' given'];
        yield 'condition count tags is an object' => [self::release('sceneTypes.0.play.3.count.tags', ['leader' => true]), 'sceneTypes[0].play[3].count.tags: must be a list'];
        yield 'condition count unknown tag' => [self::release('sceneTypes.0.play.3.count.tags.0', 'ghost'), 'sceneTypes[0].play[3].count.tags[0]: unknown tag "ghost"'];
        yield 'condition count too many tags' => [self::release('sceneTypes.0.play.3.count.tags', ['leader', 'ally', 'hostile', 'a', 'b', 'c']), 'sceneTypes[0].play[3].count.tags: at most 5 tags, 6 given'];
        yield 'condition count without bands' => [self::release('sceneTypes.0.play.3.bands', []), 'sceneTypes[0].play[3].bands: at least 1 bands'];
        yield 'condition count mandatory' => [self::release('sceneTypes.0.play.2.mandatory', true), 'sceneTypes[0].play[2].mandatory: a condition step is never mandatory'];

        // Effects.
        yield 'createNpc name is empty' => [self::release(self::MEET.'.effects.1.name', ''), 'sceneTypes[0].play[4].effects[1].name: must not be empty'];
        yield 'createNpc name too long' => [self::release(self::MEET.'.effects.1.name', str_repeat('n', 101)), 'sceneTypes[0].play[4].effects[1].name: must be at most 100 characters'];
        yield 'createNpc agenda too long' => [self::release(self::MEET.'.effects.1.agenda', str_repeat('a', 2001)), 'sceneTypes[0].play[4].effects[1].agenda: must be at most 2000 characters'];
        yield 'createNpc tags is a string' => [self::release(self::MEET.'.effects.1.tags', 'ally'), 'sceneTypes[0].play[4].effects[1].tags: must be a list'];
        yield 'createNpc unknown tag' => [self::release(self::MEET.'.effects.1.tags', ['ghost']), 'sceneTypes[0].play[4].effects[1].tags[0]: unknown tag "ghost"'];
        yield 'createNpc too many tags' => [self::release(self::MEET.'.effects.1.tags', ['leader', 'ally', 'hostile', 'a', 'b', 'c']), 'sceneTypes[0].play[4].effects[1].tags: at most 5 tags, 6 given'];
        yield 'createThread name too long' => [self::release(self::MEET.'.effects.3.name', str_repeat('n', 201)), 'sceneTypes[0].play[4].effects[3].name: must be at most 200 characters'];
        yield 'createThread agenda' => [self::release(self::MEET.'.effects.3.agenda', 'No'), 'sceneTypes[0].play[4].effects[3].agenda: unknown property'];
        yield 'createThread duplicate tag' => [self::release(self::MEET.'.effects.3.tags', ['ally', 'ally']), 'sceneTypes[0].play[4].effects[3].tags[1]: duplicate tag "ally"'];
        yield 'closeThread tags' => [self::release(self::MEET.'.effects.4.tags', ['ally']), 'sceneTypes[0].play[4].effects[4].tags: unknown property'];
        yield 'table entry effect with an unknown tag' => [self::release('oracles.tables.0.entries.0.effects.0.tags.0', 'ghost'), 'oracles.tables[0].entries[0].effects[0].tags[0]: unknown tag "ghost"'];
        yield 'too many effects' => [self::release(self::MEET.'.effects', array_fill(0, 11, ['kind' => 'closeThread'])), 'sceneTypes[0].play[4].effects: at most 10 effects, 11 given'];

        // Placeholders.
        yield 'picked with an argument' => [self::release(self::PICK.'.title', 'Meet {picked:x}'), self::PICK_PATH.'.title: placeholder {picked:x} is not supported in schema version 2'];
        yield 'picked with an argument in an agenda' => [self::release(self::MEET.'.effects.1.agenda', '{picked:leader}'), 'sceneTypes[0].play[4].effects[1].agenda: placeholder {picked:leader} is not supported in schema version 2'];
        yield 'answer in a step title' => [self::release(self::MEET.'.title', 'Who is {answer}?'), 'sceneTypes[0].play[4].title: placeholder {answer} is only allowed in effect text'];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('invalidReleases')]
    public function itRejectsMalformedPartsWithTheirPath(array $release, string $message): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }
}
