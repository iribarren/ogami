<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Catalog;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\Placeholders;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\StepParts;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts feature 8b adds to schema version 2: fact slot hints, the fillFact effect and the
 * {fact:key} placeholder. Run against valid/v2-campaign-facts.json. Its indexes: fact slots city
 * (0, text), prince (1, npc), goal (2, thread, null hint) and ambition (3, text); table rumours
 * (0: city-rumour with a fillFact); Scene Type scene (0) with setup step city (0) and play steps
 * prince (0) and ambition (1, with four effects: fillFact with text, with null text, createThread,
 * fillFact on the thread slot).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(StepParts::class)]
#[CoversClass(Effects::class)]
#[CoversClass(Placeholders::class)]
final class ReleaseContentCampaignFactsTest extends TestCase
{
    private const string AMBITION = 'sceneTypes.0.play.1';
    private const string AMBITION_PATH = 'sceneTypes[0].play[1]';

    /**
     * @return array<mixed>
     */
    private static function release(?string $path = null, mixed $value = null): array
    {
        $release = ReleaseArrays::fixture('valid/v2-campaign-facts');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    #[Test]
    public function itAcceptsTheNewPartsAndKeepsTheCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $canonical = $content->toArray();

        self::assertSame([], $content->warnings());
        self::assertSame([
            ['key' => 'city', 'label' => 'City', 'type' => 'text', 'hint' => 'Where the chronicle begins.'],
            ['key' => 'prince', 'label' => 'Prince', 'type' => 'npc', 'hint' => 'Who rules the city.'],
            ['key' => 'goal', 'label' => 'Goal', 'type' => 'thread'],
            ['key' => 'ambition', 'label' => 'Ambition', 'type' => 'text'],
        ], $canonical['factSlots'], 'A null hint is omitted.');

        /** @var list<array{play: list<array{effects: list<array<string, mixed>>}>}> $sceneTypes */
        $sceneTypes = $canonical['sceneTypes'];
        self::assertSame(
            [['kind' => 'fillFact', 'slot' => 'ambition', 'text' => '{answer}, in {fact:city}'], ['kind' => 'fillFact', 'slot' => 'ambition'], ['kind' => 'createThread', 'name' => 'Reach {fact:ambition}'], ['kind' => 'fillFact', 'slot' => 'goal']],
            $sceneTypes[0]['play'][1]['effects'],
            'A null text is omitted.',
        );
        self::assertSame(ReleaseContent::fromArray($canonical)->hash(), $content->hash());
    }

    #[Test]
    public function aReleaseWithoutTheNewPartsReadsAsBefore(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-threads-npcs');
        $canonical = ReleaseContent::fromArray($release)->toArray();

        self::assertSame([], $canonical['factSlots']);
        self::assertSame(ReleaseContent::fromArray($canonical)->hash(), ReleaseContent::fromArray($release)->hash());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function factPlaceholders(): iterable
    {
        yield 'step title' => ['sceneTypes.0.play.0.title', 'Rule of {fact:city}'];
        yield 'step prompt' => ['sceneTypes.0.setup.0.prompt', 'In {fact:city}, {fact:city}'];
        yield 'step tip' => ['sceneTypes.0.play.0.tip', 'Ambition: {fact:ambition}'];
        yield 'sceneTitle title' => [self::AMBITION.'.effects.0', ['kind' => 'sceneTitle', 'title' => 'Of {fact:prince}']];
        yield 'createNpc agenda' => ['sceneTypes.0.play.0.effects.0.agenda', 'Rules {fact:city}'];
        yield 'fillFact text' => [self::AMBITION.'.effects.0.text', '{answer} and {fact:goal}'];
        yield 'table entry text' => ['oracles.tables.0.entries.0.effects.0', ['kind' => 'createThread', 'name' => '{fact:prince}']];
    }

    #[Test]
    #[DataProvider('factPlaceholders')]
    public function aFactPlaceholderIsAllowedInAnyPlaceholderText(string $path, mixed $value): void
    {
        self::assertSame([], ReleaseContent::fromArray(self::release($path, $value))->warnings());
    }

    #[Test]
    public function aTextOnAThreadSlotFailsAsOnAnNpcSlot(): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains(self::AMBITION_PATH.'.effects[3].text: only allowed for a text slot, fact slot "goal" is a thread slot');

        ReleaseContent::fromArray(self::release(self::AMBITION.'.effects.3.text', 'The goal'));
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidReleases(): iterable
    {
        // Fact slot hints.
        yield 'hint is a number' => [self::release('factSlots.0.hint', 3), 'factSlots[0].hint: must be a string'];
        yield 'hint too long' => [self::release('factSlots.0.hint', str_repeat('h', 501)), 'factSlots[0].hint: must be at most 500 characters'];
        yield 'unknown fact slot property' => [self::release('factSlots.0.color', 'red'), 'factSlots[0].color: unknown property'];

        // fillFact.
        yield 'fillFact without slot' => [ReleaseArrays::without(self::release(), self::AMBITION.'.effects.0.slot'), self::AMBITION_PATH.'.effects[0].slot: required'];
        yield 'fillFact slot is a number' => [self::release(self::AMBITION.'.effects.0.slot', 3), self::AMBITION_PATH.'.effects[0].slot: must be a string'];
        yield 'fillFact slot is not a key' => [self::release(self::AMBITION.'.effects.0.slot', 'Not A Key'), self::AMBITION_PATH.'.effects[0].slot: must be 1 to 64 characters'];
        yield 'fillFact unknown slot' => [self::release(self::AMBITION.'.effects.0.slot', 'ghost'), self::AMBITION_PATH.'.effects[0].slot: unknown fact slot "ghost"'];
        yield 'fillFact in a table entry names an unknown slot' => [self::release('oracles.tables.0.entries.0.effects.0.slot', 'ghost'), 'oracles.tables[0].entries[0].effects[0].slot: unknown fact slot "ghost"'];
        yield 'fillFact text is a number' => [self::release(self::AMBITION.'.effects.0.text', 3), self::AMBITION_PATH.'.effects[0].text: must be a string'];
        yield 'fillFact text is empty' => [self::release(self::AMBITION.'.effects.0.text', ''), self::AMBITION_PATH.'.effects[0].text: must not be empty'];
        yield 'fillFact text is blank' => [self::release(self::AMBITION.'.effects.0.text', ' '), self::AMBITION_PATH.'.effects[0].text: must not be blank'];
        yield 'fillFact text too long' => [self::release(self::AMBITION.'.effects.0.text', str_repeat('t', 2001)), self::AMBITION_PATH.'.effects[0].text: must be at most 2000 characters'];
        yield 'fillFact text on an npc slot' => [self::release(self::AMBITION.'.effects.0.slot', 'prince'), self::AMBITION_PATH.'.effects[0].text: only allowed for a text slot, fact slot "prince" is an npc slot'];
        yield 'fillFact unknown property' => [self::release(self::AMBITION.'.effects.1.tags', ['a']), self::AMBITION_PATH.'.effects[1].tags: unknown property'];
        yield 'fillFact text placeholder unknown' => [self::release(self::AMBITION.'.effects.0.text', 'Of {fact:ghost}'), self::AMBITION_PATH.'.effects[0].text: placeholder {fact:ghost}: unknown fact slot "ghost"'];
        yield 'fillFact text answer in a step' => [self::release('sceneTypes.0.play.0.title', '{answer}'), 'sceneTypes[0].play[0].title: placeholder {answer} is only allowed in effect text'];

        // {fact:key}.
        yield 'unknown fact slot' => [self::release('sceneTypes.0.play.0.title', 'Of {fact:ghost}'), 'sceneTypes[0].play[0].title: placeholder {fact:ghost}: unknown fact slot "ghost"'];
        yield 'unknown fact slot in an effect' => [self::release('sceneTypes.0.play.0.effects.0.name', '{fact:ghost}'), 'sceneTypes[0].play[0].effects[0].name: placeholder {fact:ghost}: unknown fact slot "ghost"'];
        yield 'unknown fact slot in a table entry effect' => [self::release('oracles.tables.0.entries.0.effects.0', ['kind' => 'createThread', 'name' => '{fact:ghost}']), 'oracles.tables[0].entries[0].effects[0].name: placeholder {fact:ghost}: unknown fact slot "ghost"'];
        yield 'fact without a key' => [self::release('sceneTypes.0.play.0.title', 'Of {fact}'), 'sceneTypes[0].play[0].title: placeholder {fact} needs a fact slot key, as in {fact:city}'];
        yield 'another namespace is still unsupported' => [self::release('sceneTypes.0.play.0.title', 'Of {npc:city}'), 'sceneTypes[0].play[0].title: placeholder {npc:city} is not supported in schema version 2'];
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
