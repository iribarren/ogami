<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\CreateNpcEffect;
use App\Play\Domain\GameSystem\Flow\CreateThreadEffect;
use App\Play\Domain\GameSystem\Flow\FillFactEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the Campaign facts parts of schema version 2: fact slot
 * hints and the fillFact Effect. It fails closed on anything Studio would have rejected, and
 * releases without these parts read as before.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(FactSlot::class)]
#[CoversClass(FillFactEffect::class)]
final class GameSystemReleaseTranslatorCampaignFactsTest extends TestCase
{
    private const string FIXTURE = 'valid/v2-campaign-facts';

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    /**
     * @return list<object>
     */
    private function effects(string $step, ?GameSystemSnapshot $snapshot = null): array
    {
        $snapshot ??= $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));
        $list = 'city' === $step ? $snapshot->sceneType('scene')?->setup : $snapshot->sceneType('scene')?->play;

        return $list?->step($step)->effects ?? self::fail(\sprintf('No step "%s".', $step));
    }

    #[Test]
    public function itMapsFactSlotsWithTheirHints(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        self::assertEquals([
            new FactSlot('city', 'City', FactSlotType::Text, 'Where the chronicle begins.'),
            new FactSlot('prince', 'Prince', FactSlotType::Npc, 'Who rules the city.'),
            new FactSlot('goal', 'Goal', FactSlotType::Thread),
            new FactSlot('ambition', 'Ambition', FactSlotType::Text),
        ], $snapshot->factSlots());
        self::assertNull($snapshot->factSlots()[2]->hint, 'A null hint reads as none.');
        self::assertEquals(new FactSlot('prince', 'Prince', FactSlotType::Npc, 'Who rules the city.'), $snapshot->factSlot('prince'));
        self::assertNull($snapshot->factSlot('ghost'));
    }

    #[Test]
    public function itMapsFillFactWithAndWithoutText(): void
    {
        self::assertEquals([new FillFactEffect('city')], $this->effects('city'));
        self::assertEquals([
            new FillFactEffect('ambition', '{answer}, in {fact:city}'),
            new FillFactEffect('ambition'),
            new CreateThreadEffect('Reach {fact:ambition}'),
            new FillFactEffect('goal'),
        ], $this->effects('ambition'));
        self::assertEquals([new CreateNpcEffect('Prince of {fact:city}'), new FillFactEffect('prince')], $this->effects('prince'));
    }

    #[Test]
    public function itMapsFillFactInATableEntry(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent(self::FIXTURE));

        $entry = $snapshot->rolledEntry(new OracleTableStep('rumours', 'Rumours', '1d2', 1, 'x', null));

        self::assertEquals([new FillFactEffect('goal')], $entry->effects ?? self::fail('No entry metadata.'));
    }

    #[Test]
    public function aReleaseWithoutTheNewPartsReadsAsBefore(): void
    {
        $snapshot = $this->translate(ReleaseViews::fixtureContent('valid/v2-contract-doc-example'));

        self::assertEquals([new FactSlot('target', 'Target', FactSlotType::Text)], $snapshot->factSlots());
        self::assertNull($snapshot->factSlots()[0]->hint);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedParts(): iterable
    {
        $fill = 'sceneTypes.0.play.1.effects.0';
        $fillPath = 'sceneTypes[0].play[1].effects[0]';
        yield 'slot hint' => ['factSlots.0.hint', 5, 'factSlots[0].hint: must be a string.'];
        yield 'slot type' => ['factSlots.0.type', 'region', 'factSlots[0].type: unknown fact slot type "region".'];
        yield 'fillFact without slot' => [$fill.'.slot', null, $fillPath.'.slot: must be a string.'];
        yield 'fillFact slot type' => [$fill.'.slot', 5, $fillPath.'.slot: must be a string.'];
        yield 'fillFact unknown slot' => [$fill.'.slot', 'ghost', $fillPath.'.slot: unknown fact slot "ghost".'];
        yield 'fillFact text type' => [$fill.'.text', 5, $fillPath.'.text: must be a string.'];
        yield 'fillFact text on an npc slot' => [$fill.'.slot', 'prince', $fillPath.'.text: only a text slot takes a text, "prince" is an npc slot.'];
        yield 'table entry fillFact unknown slot' => ['oracles.tables.0.entries.0.effects.0.slot', 'ghost', 'oracles.tables[0].entries[0].effects[0].slot: unknown fact slot "ghost".'];
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
}
