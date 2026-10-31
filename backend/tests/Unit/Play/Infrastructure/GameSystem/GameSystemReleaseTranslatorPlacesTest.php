<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\CloseThreadEffect;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\CreatePlaceEffect;
use App\Play\Domain\GameSystem\Flow\ItemCount;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickStep;
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
 * Play's anti-corruption layer reads the Places parts of schema version 2 (ADR 0047): the place fact
 * slot type, Tags that apply to Places, the createPlace Effect, pick and count of Places and
 * closeThread by fact slot. It fails closed on anything Studio would have rejected, and a closeThread
 * without a fact reads as before.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(CloseThreadEffect::class)]
#[CoversClass(CreatePlaceEffect::class)]
final class GameSystemReleaseTranslatorPlacesTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-places';

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    private function step(string $key): Step
    {
        return $this->translate(ReleaseViews::fixtureContent(self::FIXTURE))->sceneType('scene')?->play->step($key) ?? self::fail(\sprintf('No step "%s".', $key));
    }

    #[Test]
    public function itMapsPlaceSlotsAndTagsThatApplyToPlaces(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        self::assertEquals([new FactSlot('site', 'Current site', FactSlotType::Place), new FactSlot('expedition', 'Expedition', FactSlotType::Thread)], $snapshot->factSlots());
        self::assertEquals([
            new Tag('near', 'Near', null, [ItemKind::Place]),
            new Tag('deep', 'Deep', 'Far from town.', [ItemKind::Place, ItemKind::Thread]),
            new Tag('ally', 'Ally', null, [ItemKind::Character, ItemKind::Npc, ItemKind::Thread, ItemKind::Place]),
        ], $snapshot->tags());
        self::assertSame([true, false, true], [$snapshot->tag('near')?->isFor(ItemKind::Place), $snapshot->tag('near')?->isFor(ItemKind::Npc), $snapshot->tag('deep')?->isFor(ItemKind::Thread)]);
    }

    #[Test]
    public function itMapsPickAndCountOfPlaces(): void
    {
        $pick = $this->step('pick-site');
        $count = $this->step('count');

        self::assertInstanceOf(PickStep::class, $pick);
        self::assertInstanceOf(ConditionStep::class, $count);
        self::assertSame([ItemKind::Place, ['near']], [$pick->of, $pick->tags]);
        self::assertEquals(new ItemCount(ItemKind::Place, ['deep']), $count->count);
    }

    #[Test]
    public function itMapsCreatePlaceAndCloseThreadWithANullOrNoFactSlotOrANamedOne(): void
    {
        self::assertEquals([
            new CreatePlaceEffect(),
            new CreatePlaceEffect('Camp near {picked}', ['near', 'ally']),
            new CloseThreadEffect(),
            new CloseThreadEffect(),
            new CloseThreadEffect('expedition'),
        ], $this->step('meet')->effects);
    }

    #[Test]
    public function itMapsTheEffectsOfATableEntry(): void
    {
        $effects = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE))->rolledEntry(new OracleTableStep('rumors', 'Rumors', '1d2', 2, 'x', null))?->effects;

        self::assertEquals([new CloseThreadEffect('expedition')], $effects);
    }

    #[Test]
    public function aCloseThreadWithANullFactReadsAsOneWithoutAFact(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent(self::FIXTURE), 'sceneTypes.0.play.2.effects', [['kind' => 'closeThread', 'fact' => null]]);

        self::assertEquals([new CloseThreadEffect()], $this->translate($content)->sceneType('scene')?->play->step('meet')?->effects);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedParts(): iterable
    {
        $meet = 'sceneTypes.0.play.2.effects';
        $meetPath = 'sceneTypes[0].play[2].effects';
        yield 'closeThread fact type' => [$meet.'.3.fact', 5, $meetPath.'[3].fact: must be a string.'];
        yield 'closeThread unknown fact slot' => [$meet.'.3.fact', 'ghost', $meetPath.'[3].fact: unknown fact slot "ghost".'];
        yield 'closeThread fact is a place slot' => [$meet.'.3.fact', 'site', $meetPath.'[3].fact: only a thread slot can be closed, "site" is a place slot.'];
        yield 'createPlace name type' => [$meet.'.1.name', 5, $meetPath.'[1].name: must be a string.'];
        yield 'createPlace tag' => [$meet.'.1.tags', ['ghost'], $meetPath.'[1].tags[0]: unknown tag "ghost".'];
        yield 'table entry createPlace tag' => ['oracles.tables.0.entries.0.effects.0.tags', ['ghost'], 'oracles.tables[0].entries[0].effects[0].tags[0]: unknown tag "ghost".'];
        yield 'table entry closeThread fact' => ['oracles.tables.0.entries.1.effects.0.fact', 'site', 'oracles.tables[0].entries[1].effects[0].fact: only a thread slot can be closed, "site" is a place slot.'];
        yield 'pick item kind' => ['sceneTypes.0.play.0.of', 'region', 'sceneTypes[0].play[0].of: unknown item kind "region".'];
        yield 'tag scope kind' => ['tags.0.appliesTo', ['place', 'region'], 'tags[0].appliesTo[1]: unknown entity kind "region".'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function malformedPartsAreAPlayError(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent(self::FIXTURE), $path, $value));
    }
}
