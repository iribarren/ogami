<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign keeps Characters whose values follow the pinned release's sheet: every rule fails
 * closed, nothing is coerced, a missing value takes the field default and every command is all or
 * nothing.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Character::class)]
#[CoversClass(CharacterId::class)]
#[CoversClass(CharacterValues::class)]
#[CoversClass(CampaignLimitReached::class)]
final class CampaignCharactersTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = $this->releaseWith([
            new TextField('role', 'Role'),
            new TextField('look', 'Look', multiline: true, maxLength: 20),
            new NumberField('reflexes', 'Reflexes', min: 1, max: 10, default: 4),
            new NumberField('luck', 'Luck'),
            new TrackField('hp', 'Hit points', max: 40),
            new TrackField('humanity', 'Humanity'),
            new ListField('gear', 'Gear', maxItems: 2),
        ]);
    }

    #[Test]
    public function aNewCampaignHoldsNoCharacters(): void
    {
        self::assertSame([], $this->campaign()->characters());
        self::assertNull($this->campaign()->character(CharacterId::fromString('c-1')));
    }

    #[Test]
    public function aCharacterTakesTheDefaultOfEveryFieldItIsNotGiven(): void
    {
        $campaign = $this->campaign();

        $character = $campaign->addCharacter(CharacterId::fromString('c-1'), '  Vi  ', ['ally'], ['  Fixer '], [], $this->release);

        self::assertSame('Vi', $character->name());
        self::assertSame(['ally'], $character->tags());
        self::assertSame(['Fixer'], $character->freeTags());
        self::assertNull($character->promotedFrom());
        self::assertSame(
            ['role' => '', 'look' => '', 'reflexes' => 4, 'luck' => 0, 'hp' => ['current' => 40, 'max' => 40], 'humanity' => ['current' => 0, 'max' => 0], 'gear' => []],
            $character->values(),
        );
        self::assertSame([$character], $campaign->characters());
        self::assertSame($character, $campaign->character(CharacterId::fromString('c-1')));
    }

    #[Test]
    public function itStoresTheGivenValuesInSheetFieldOrder(): void
    {
        $character = $this->campaign()->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [
            'gear' => ['  Knife ', 'Pistol'],
            'humanity' => ['current' => 6, 'max' => 8],
            'hp' => ['current' => 12],
            'luck' => -3,
            'role' => '  Solo ',
        ], $this->release);

        self::assertSame(['role', 'look', 'reflexes', 'luck', 'hp', 'humanity', 'gear'], array_keys($character->values()));
        self::assertSame(
            ['role' => 'Solo', 'look' => '', 'reflexes' => 4, 'luck' => -3, 'hp' => ['current' => 12, 'max' => 40], 'humanity' => ['current' => 6, 'max' => 8], 'gear' => ['Knife', 'Pistol']],
            $character->values(),
        );
    }

    #[Test]
    public function aDerivedFieldHoldsNoValue(): void
    {
        $release = $this->releaseWith([new NumberField('luck', 'Luck'), new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{luck} * 2'))]);

        $character = $this->campaign()->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 3], $release);

        self::assertSame(['luck' => 3], $character->values(), 'Nothing is stored for the derived field.');
    }

    #[Test]
    public function aValueGivenForADerivedFieldIsRefusedAndNothingIsAdded(): void
    {
        $release = $this->releaseWith([new NumberField('luck', 'Luck'), new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{luck} * 2'))]);
        $campaign = $this->campaign();

        try {
            $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['dodge' => 6], $release);
            self::fail('A value for a derived field was accepted.');
        } catch (InvalidCharacter $exception) {
            self::assertSame('Field "dodge" is derived; it holds no value.', $exception->getMessage());
            self::assertSame([], $campaign->characters());
        }
    }

    #[Test]
    public function aReleaseWithoutFieldsGivesACharacterWithANameAndTagsOnly(): void
    {
        $release = $this->releaseWith([]);

        $character = $this->campaign()->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $release);

        self::assertSame([], $character->values());
    }

    #[Test]
    public function aReleaseWithoutFieldsRefusesAnyValue(): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains('The sheet has no field "role".');

        $this->campaign()->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['role' => 'Solo'], $this->releaseWith([]));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'an unknown key' => [['ghost' => 1], 'The sheet has no field "ghost".'];
        yield 'a number as a string' => [['luck' => '3'], 'Field "luck" must be an integer.'];
        yield 'a number as a float' => [['luck' => 3.0], 'Field "luck" must be an integer.'];
        yield 'a number as a bool' => [['luck' => true], 'Field "luck" must be an integer.'];
        yield 'a number below min' => [['reflexes' => 0], 'Field "reflexes" must be 1 to 10, got 0.'];
        yield 'a number above max' => [['reflexes' => 11], 'Field "reflexes" must be 1 to 10, got 11.'];
        yield 'a number above the contract limit' => [['luck' => 1_000_001], 'Field "luck" must be -1000000 to 1000000, got 1000001.'];
        yield 'a text that is a number' => [['role' => 5], 'Field "role" must be a string.'];
        yield 'a text over the limit' => [['look' => str_repeat('l', 21)], 'Field "look" must be at most 20 characters, got 21.'];
        yield 'a single-line text with a newline' => [['role' => "Solo\nFixer"], 'Field "role" is a single line.'];
        yield 'a list that is a string' => [['gear' => 'Knife'], 'Field "gear" must be a list of strings.'];
        yield 'a list with keys' => [['gear' => ['a' => 'Knife']], 'Field "gear" must be a list of strings.'];
        yield 'a list item that is a number' => [['gear' => [1]], 'Field "gear" must be a list of strings.'];
        yield 'a blank list item' => [['gear' => ['Knife', '  ']], 'An item of field "gear" must be 1 to 200 characters, got 0.'];
        yield 'a long list item' => [['gear' => [str_repeat('k', 201)]], 'An item of field "gear" must be 1 to 200 characters, got 201.'];
        yield 'too many list items' => [['gear' => ['a', 'b', 'c']], 'Field "gear" holds at most 2 items, got 3.'];
        yield 'a track that is a number' => [['hp' => 5], 'Field "hp" must be {current, max}.'];
        yield 'a track with an unknown part' => [['hp' => ['current' => 1, 'min' => 0]], 'Field "hp" must be {current, max}.'];
        yield 'a track without current' => [['hp' => ['max' => 40]], 'Field "hp" must be {current, max} with integers.'];
        yield 'a track with a string current' => [['hp' => ['current' => '5']], 'Field "hp" must be {current, max} with integers.'];
        yield 'a track with a null max against its fixed max' => [['hp' => ['current' => 5, 'max' => null]], 'Field "hp" must be {current, max} with integers.'];
        yield 'a track against its fixed max' => [['hp' => ['current' => 5, 'max' => 30]], 'Field "hp" has a fixed max of 40, got 30.'];
        yield 'a track above its max' => [['hp' => ['current' => 41]], 'The current of field "hp" must be 0 to 40, got 41.'];
        yield 'a track below zero' => [['hp' => ['current' => -1]], 'The current of field "hp" must be 0 to 40, got -1.'];
        yield 'a track without a max to set' => [['humanity' => ['current' => 1]], 'Field "humanity" must be {current, max} with integers.'];
        yield 'a track max above 999' => [['humanity' => ['current' => 1, 'max' => 1000]], 'The max of field "humanity" must be 0 to 999, got 1000.'];
        yield 'a track max below zero' => [['humanity' => ['current' => 0, 'max' => -1]], 'The max of field "humanity" must be 0 to 999, got -1.'];
    }

    /**
     * @param array<mixed> $values
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatBreaksItsFieldIsRefusedAndNothingIsAdded(array $values, string $message): void
    {
        $campaign = $this->campaign();

        try {
            $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], $values, $this->release);
            self::fail('The value was accepted.');
        } catch (InvalidCharacter $invalid) {
            self::assertSame($message, $invalid->getMessage());
        }

        self::assertSame([], $campaign->characters());
    }

    #[Test]
    public function itAcceptsValuesExactlyAtTheLimits(): void
    {
        $character = $this->campaign()->addCharacter(CharacterId::fromString('c-1'), str_repeat('n', 100), [], [], [
            'look' => str_repeat('l', 20),
            'reflexes' => 10,
            'luck' => -1_000_000,
            'hp' => ['current' => 0, 'max' => 40],
            'humanity' => ['current' => 999, 'max' => 999],
            'gear' => [str_repeat('k', 200), 'b'],
        ], $this->release);

        self::assertSame(['current' => 999, 'max' => 999], $character->values()['humanity']);
    }

    #[Test]
    public function aMultilineTextKeepsItsNewlines(): void
    {
        $character = $this->campaign()->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['look' => "Tall\nScarred"], $this->release);

        self::assertSame("Tall\nScarred", $character->values()['look']);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, string}>
     */
    public static function invalidCharacters(): iterable
    {
        yield 'a blank name' => ['  ', [], [], 'The name must not be blank.'];
        yield 'a long name' => [str_repeat('n', 101), [], [], 'The name must be at most 100 characters, got 101.'];
        yield 'a repeated tag' => ['Vi', ['ally', 'ally'], [], 'Tag "ally" is listed twice.'];
        yield 'a blank free tag' => ['Vi', [], [' '], 'A free tag must be 1 to 50 characters, got 0.'];
    }

    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    #[Test]
    #[DataProvider('invalidCharacters')]
    public function theNameAndTagsFollowTheRulesOfAnNpc(string $name, array $tags, array $freeTags, string $message): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->campaign()->addCharacter(CharacterId::fromString('c-1'), $name, $tags, $freeTags, [], $this->release);
    }

    #[Test]
    public function aReleaseTagMustBeInThePinnedRelease(): void
    {
        $campaign = $this->campaign();

        try {
            $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ghost'], [], [], $this->release);
            self::fail('The tag was accepted.');
        } catch (UnknownReleaseTag) {
        }

        self::assertSame([], $campaign->characters());
    }

    #[Test]
    public function aBlankIdIsRefused(): void
    {
        $this->expectException(InvalidCharacter::class);

        CharacterId::fromString(' ');
    }

    #[Test]
    public function aRepeatedIdDoesNotReplaceTheCharacter(): void
    {
        $campaign = $this->campaign();
        $first = $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $this->release);

        $this->expectException(CharacterAlreadyExists::class);

        try {
            $campaign->addCharacter(CharacterId::fromString('c-1'), 'Other', [], [], [], $this->release);
        } finally {
            self::assertSame([$first], $campaign->characters());
        }
    }

    #[Test]
    public function aCampaignHoldsAtMost100Characters(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_CHARACTERS; ++$i) {
            $campaign->addCharacter(CharacterId::fromString('c-'.$i), 'Vi '.$i, [], [], [], $this->release);
        }

        try {
            $campaign->addCharacter(CharacterId::fromString('c-101'), 'One more', [], [], [], $this->release);
            self::fail('The 101st Character was added.');
        } catch (CampaignLimitReached $limit) {
            self::assertSame('A campaign holds at most 100 Characters.', $limit->getMessage());
        }

        self::assertCount(100, $campaign->characters());
    }

    #[Test]
    public function charactersAreRebuiltAndTheirIdsMustBeDistinct(): void
    {
        $vi = Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], []);
        $campaign = Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'), [], characters: [$vi]);

        self::assertSame([$vi], $campaign->characters());

        $this->expectException(CharacterAlreadyExists::class);

        Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'), [], characters: [$vi, $vi]);
    }

    #[Test]
    public function aStoredCharacterWithValuesOfNoFieldTypeIsRefused(): void
    {
        $this->expectException(InvalidCharacter::class);

        Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 1.5]);
    }

    #[Test]
    public function aStoredTrackAboveItsMaxIsRefused(): void
    {
        $this->expectException(InvalidCharacter::class);

        Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['current' => 5, 'max' => 3]]);
    }

    #[Test]
    public function editingReplacesEverythingAndAMissingValueGoesBackToItsDefault(): void
    {
        $campaign = $this->campaign();
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], ['Fixer'], ['role' => 'Solo', 'luck' => 5, 'hp' => ['current' => 3]], $this->release);

        $edited = $campaign->editCharacter(CharacterId::fromString('c-1'), ' Violet ', ['rival'], [], ['luck' => 2], $this->release);

        self::assertSame('Violet', $edited->name());
        self::assertSame(['rival'], $edited->tags());
        self::assertSame([], $edited->freeTags());
        self::assertSame('', $edited->values()['role']);
        self::assertSame(2, $edited->values()['luck']);
        self::assertSame(['current' => 40, 'max' => 40], $edited->values()['hp']);
        self::assertSame([$edited], $campaign->characters());
    }

    #[Test]
    public function aFailedEditChangesNothing(): void
    {
        $campaign = $this->campaign();
        $original = $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], [], ['luck' => 5], $this->release);

        foreach ([['Violet', ['ally'], ['luck' => 'many']], ['Violet', ['ghost'], ['luck' => 6]], ['  ', ['ally'], ['luck' => 6]]] as [$name, $tags, $values]) {
            try {
                $campaign->editCharacter(CharacterId::fromString('c-1'), $name, $tags, [], $values, $this->release);
                self::fail('The edit was accepted.');
            } catch (InvalidCharacter|UnknownReleaseTag) {
            }

            self::assertSame([$original], $campaign->characters());
        }
    }

    #[Test]
    public function editingAnUnknownCharacterFails(): void
    {
        $this->expectException(UnknownCharacter::class);
        $this->expectExceptionMessageIsOrContains('Character "ghost" not found.');

        $this->campaign()->editCharacter(CharacterId::fromString('ghost'), 'Vi', [], [], [], $this->release);
    }

    #[Test]
    public function aCharacterKeepsTheNpcItWasPromotedFromWhenEdited(): void
    {
        $promoted = Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], [], NpcId::fromString('npc-1'));

        $edited = $promoted->edited('Mira Vale', [], [], []);

        self::assertSame('npc-1', $edited->promotedFrom()?->toString());
    }

    /**
     * @param list<Field> $fields
     */
    private function releaseWith(array $fields): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival')], sheet: new SheetTemplate($fields));
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
    }
}
