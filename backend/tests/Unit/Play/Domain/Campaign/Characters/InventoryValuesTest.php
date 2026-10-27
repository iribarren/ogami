<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterCreation;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Tests\Unit\Play\Domain\GameSystem\Sheet\InventoryFieldTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value of an inventory field (ADR 0045) on a sheet of "pack" (Gear and Food, 6 slots, 3 stacks)
 * and "chrome" (Chrome, no slot limit): a list of catalog stacks {gear, quantity} and free stacks
 * {name, quantity, slots}. Every rule fails closed; nothing is coerced.
 */
#[CoversClass(CharacterValues::class)]
#[CoversClass(CharacterCreation::class)]
#[CoversClass(Campaign::class)]
#[CoversClass(Character::class)]
final class InventoryValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<array<string, int|string>>}> the given value and the value stored
     */
    public static function validValues(): iterable
    {
        yield 'no stack' => [[], []];
        yield 'a catalog stack' => [[['gear' => 'pistol', 'quantity' => 2]], [['gear' => 'pistol', 'quantity' => 2]]];
        yield 'a free stack' => [[['name' => 'Lockpick', 'quantity' => 1, 'slots' => 0]], [['name' => 'Lockpick', 'quantity' => 1, 'slots' => 0]]];
        yield 'both kinds in order' => [
            [['gear' => 'kibble', 'quantity' => 3], ['name' => 'Rope', 'quantity' => 1, 'slots' => 2]],
            [['gear' => 'kibble', 'quantity' => 3], ['name' => 'Rope', 'quantity' => 1, 'slots' => 2]],
        ];
        yield 'keys in another order' => [[['quantity' => 2, 'gear' => 'pistol']], [['gear' => 'pistol', 'quantity' => 2]]];
        yield 'free keys in another order' => [[['slots' => 1, 'quantity' => 2, 'name' => 'Rope']], [['name' => 'Rope', 'quantity' => 2, 'slots' => 1]]];
        yield 'a trimmed name' => [[['name' => '  Rope ', 'quantity' => 1, 'slots' => 1]], [['name' => 'Rope', 'quantity' => 1, 'slots' => 1]]];
        yield 'the capacity exactly' => [[['gear' => 'jacket', 'quantity' => 3]], [['gear' => 'jacket', 'quantity' => 3]]];
        yield 'zero-slot gear above the capacity' => [[['gear' => 'pistol', 'quantity' => 2], ['name' => 'Charm', 'quantity' => 9999, 'slots' => 0]], [['gear' => 'pistol', 'quantity' => 2], ['name' => 'Charm', 'quantity' => 9999, 'slots' => 0]]];
        yield 'the maximum stacks' => [
            [['gear' => 'pistol', 'quantity' => 1], ['name' => 'A', 'quantity' => 1, 'slots' => 0], ['name' => 'B', 'quantity' => 1, 'slots' => 0]],
            [['gear' => 'pistol', 'quantity' => 1], ['name' => 'A', 'quantity' => 1, 'slots' => 0], ['name' => 'B', 'quantity' => 1, 'slots' => 0]],
        ];
    }

    /**
     * @param list<array<string, int|string>> $stored
     */
    #[Test]
    #[DataProvider('validValues')]
    public function stacksWithinTheLimitsAreStoredInTheirCanonicalForm(mixed $given, array $stored): void
    {
        self::assertSame($stored, CharacterValues::resolve($this->sheet(), ['pack' => $given])['pack']);
        self::assertSame($stored, Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['pack' => $stored])->values()['pack'], 'A resolved value is well formed.');
    }

    /**
     * @return iterable<string, array{mixed, string}> the given value and the message
     */
    public static function invalidValues(): iterable
    {
        yield 'a string' => ['pistol', 'Field "pack" must be a list of stacks.'];
        yield 'an object' => [['gear' => 'pistol', 'quantity' => 1], 'Field "pack" must be a list of stacks.'];
        yield 'a stack that is a string' => [['pistol'], 'A stack of field "pack" must be {gear, quantity} or {name, quantity, slots}.'];
        yield 'a stack that is a list' => [[['pistol', 1]], 'A stack of field "pack" must be {gear, quantity} or {name, quantity, slots}.'];
        yield 'an unknown key on a catalog stack' => [[['gear' => 'pistol', 'quantity' => 1, 'slots' => 1]], 'A stack of field "pack" must have exactly gear, quantity.'];
        yield 'an unknown key on a free stack' => [[['name' => 'Rope', 'quantity' => 1, 'slots' => 1, 'cost' => 3]], 'A stack of field "pack" must have exactly name, quantity, slots.'];
        yield 'a catalog stack without a quantity' => [[['gear' => 'pistol']], 'A stack of field "pack" must have exactly gear, quantity.'];
        yield 'a free stack without slots' => [[['name' => 'Rope', 'quantity' => 1]], 'A stack of field "pack" must have exactly name, quantity, slots.'];
        yield 'a stack of neither kind' => [[['quantity' => 1]], 'A stack of field "pack" must have exactly name, quantity, slots.'];
        yield 'a gear that is not a string' => [[['gear' => 4, 'quantity' => 1]], 'The gear of a stack of field "pack" must be a catalog key.'];
        yield 'an empty gear key' => [[['gear' => '', 'quantity' => 1]], 'The gear of a stack of field "pack" must be a catalog key.'];
        yield 'gear that is unknown' => [[['gear' => 'katana', 'quantity' => 1]], 'Field "pack" does not accept gear "katana".'];
        yield 'gear of a category it does not accept' => [[['gear' => 'optic', 'quantity' => 1]], 'Field "pack" does not accept gear "optic".'];
        yield 'gear without a category' => [[['gear' => 'box', 'quantity' => 1]], 'Field "pack" does not accept gear "box".'];
        yield 'the same gear in two stacks' => [[['gear' => 'pistol', 'quantity' => 1], ['gear' => 'pistol', 'quantity' => 1]], 'Field "pack" holds gear "pistol" in two stacks.'];
        yield 'a quantity of zero' => [[['gear' => 'pistol', 'quantity' => 0]], 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'a quantity of 10,000' => [[['gear' => 'pistol', 'quantity' => 10000]], 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'a quantity that is a string' => [[['gear' => 'pistol', 'quantity' => '2']], 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'a quantity that is a float' => [[['gear' => 'pistol', 'quantity' => 2.0]], 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'a name that is not a string' => [[['name' => 4, 'quantity' => 1, 'slots' => 0]], 'The name of a stack of field "pack" must be a string.'];
        yield 'a blank name' => [[['name' => '  ', 'quantity' => 1, 'slots' => 0]], 'The name of a stack of field "pack" must be 1 to 200 characters, got 0.'];
        yield 'a name of 201 characters' => [[['name' => str_repeat('a', 201), 'quantity' => 1, 'slots' => 0]], 'The name of a stack of field "pack" must be 1 to 200 characters, got 201.'];
        yield 'slots below zero' => [[['name' => 'Rope', 'quantity' => 1, 'slots' => -1]], 'The slots of a stack of field "pack" must be an integer from 0 to 100.'];
        yield 'slots above 100' => [[['name' => 'Rope', 'quantity' => 1, 'slots' => 101]], 'The slots of a stack of field "pack" must be an integer from 0 to 100.'];
        yield 'slots that are a string' => [[['name' => 'Rope', 'quantity' => 1, 'slots' => '1']], 'The slots of a stack of field "pack" must be an integer from 0 to 100.'];
        yield 'one slot over the capacity' => [[['gear' => 'jacket', 'quantity' => 3], ['name' => 'Rope', 'quantity' => 1, 'slots' => 1]], 'Field "pack" holds 6 slots, 7 used.'];
        yield 'a catalog stack over the capacity' => [[['gear' => 'pistol', 'quantity' => 7]], 'Field "pack" holds 6 slots, 7 used.'];
        yield 'a free stack over the capacity' => [[['name' => 'Anvil', 'quantity' => 1, 'slots' => 7]], 'Field "pack" holds 6 slots, 7 used.'];
        yield 'one stack over the maximum' => [
            [['gear' => 'pistol', 'quantity' => 1], ['name' => 'A', 'quantity' => 1, 'slots' => 0], ['name' => 'B', 'quantity' => 1, 'slots' => 0], ['name' => 'C', 'quantity' => 1, 'slots' => 0]],
            'Field "pack" holds at most 3 stacks, got 4.',
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueBreakingARuleFailsClosedWithItsReason(mixed $given, string $message): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains($message);

        CharacterValues::resolve($this->sheet(), ['pack' => $given]);
    }

    #[Test]
    public function aFieldWithoutACapacityHasNoSlotLimit(): void
    {
        $given = [['name' => 'Anvil', 'quantity' => 9999, 'slots' => 100]];

        self::assertSame($given, CharacterValues::resolve($this->sheet(), ['chrome' => $given])['chrome']);
    }

    #[Test]
    public function aCharacterStartsWithAnEmptyInventoryWhenGivenNone(): void
    {
        self::assertSame([], CharacterValues::resolve($this->sheet(), [])['pack']);
        self::assertSame([], CharacterCreation::values($this->sheet(), [], null, null)['pack']);
        self::assertSame([], CharacterCreation::promoted($this->sheet(), null)['pack']);
    }

    #[Test]
    public function creationChecksTheInventoryLikeAnEdit(): void
    {
        self::assertSame([['gear' => 'pistol', 'quantity' => 1]], CharacterCreation::values($this->sheet(), ['pack' => [['gear' => 'pistol', 'quantity' => 1]]], null, null)['pack']);

        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains('Field "pack" does not accept gear "optic".');

        CharacterCreation::values($this->sheet(), ['pack' => [['gear' => 'optic', 'quantity' => 1]]], null, null);
    }

    #[Test]
    public function aStoredValueOfStacksIsWellFormedWithoutTheSheet(): void
    {
        $stacks = [['gear' => 'pistol', 'quantity' => 2], ['name' => 'Rope', 'quantity' => 1, 'slots' => 2]];

        self::assertSame($stacks, CharacterValues::assertWellFormed(['pack' => $stacks, 'luck' => 3])['pack']);
        self::assertSame([], CharacterValues::assertWellFormed(['pack' => []])['pack']);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}> the stored values and the message
     */
    public static function malformedStoredValues(): iterable
    {
        yield 'stacks and a string' => [[['gear' => 'pistol', 'quantity' => 1], 'rope'], 'A stack of field "pack" must be {gear, quantity} or {name, quantity, slots}.'];
        yield 'a quantity of zero' => [[['gear' => 'pistol', 'quantity' => 0]], 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'a gear twice' => [[['gear' => 'pistol', 'quantity' => 1], ['gear' => 'pistol', 'quantity' => 2]], 'Field "pack" holds gear "pistol" in two stacks.'];
        yield 'an unknown key' => [[['gear' => 'pistol', 'quantity' => 1, 'cost' => 4]], 'A stack of field "pack" must have exactly gear, quantity.'];
        yield '101 stacks' => [array_map(static fn (int $n): array => ['name' => 'N'.$n, 'quantity' => 1, 'slots' => 0], range(1, 101)), 'Field "pack" holds at most 100 stacks, got 101.'];
    }

    /**
     * @param array<mixed> $stored
     */
    #[Test]
    #[DataProvider('malformedStoredValues')]
    public function aStoredValueBreakingTheShapeFailsClosed(array $stored, string $message): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains($message);

        Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['pack' => $stored]);
    }

    #[Test]
    public function aStoredCharacterWithoutAnInventoryReadsUnchanged(): void
    {
        $legacy = ['role' => 'Solo', 'luck' => 3, 'hp' => ['current' => 4, 'max' => 5], 'gear' => ['Knife', 'Pistol'], 'wounds' => []];

        $character = Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], $legacy);

        self::assertSame($legacy, $character->values());
    }

    #[Test]
    public function anEditReplacesTheInventoryAsAWholeOrNotAtAll(): void
    {
        $campaign = $this->campaign();
        $release = $this->release();
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 2, 'pack' => [['gear' => 'pistol', 'quantity' => 1]]], $release);

        $campaign->editCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 5, 'pack' => [['gear' => 'kibble', 'quantity' => 2]]], $release);
        $before = $campaign->character(CharacterId::fromString('c-1'))?->values();
        self::assertSame([['gear' => 'kibble', 'quantity' => 2]], $before['pack'] ?? null);

        try {
            $campaign->editCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 9, 'pack' => [['gear' => 'optic', 'quantity' => 1]]], $release);
            self::fail('The edit should have been refused.');
        } catch (InvalidCharacter) {
            self::assertSame($before, $campaign->character(CharacterId::fromString('c-1'))?->values(), 'Nothing changed.');
        }
    }

    #[Test]
    public function aValueSetOnAStoredCharacterIsCheckedForItsShape(): void
    {
        $campaign = $this->campaign();
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $this->release());

        $campaign->changeCharacterValue(CharacterId::fromString('c-1'), 'pack', [['gear' => 'pistol', 'quantity' => 2]]);
        self::assertSame([['gear' => 'pistol', 'quantity' => 2]], $campaign->character(CharacterId::fromString('c-1'))?->values()['pack']);

        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains('The quantity of a stack of field "pack" must be an integer from 1 to 9999.');

        $campaign->changeCharacterValue(CharacterId::fromString('c-1'), 'pack', [['gear' => 'pistol', 'quantity' => 0]]);
    }

    private function sheet(): SheetTemplate
    {
        $catalog = InventoryFieldTest::catalog();

        return new SheetTemplate([
            new NumberField('luck', 'Luck'),
            new InventoryField('pack', 'Pack', capacity: 6, maxStacks: 3, categories: ['Gear', 'Food'], catalog: $catalog),
            new InventoryField('chrome', 'Chrome', catalog: $catalog),
        ]);
    }

    private function release(): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], sheet: $this->sheet(), gear: InventoryFieldTest::catalog());
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
    }
}
