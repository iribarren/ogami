<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * A player character of a campaign: a name, release Tags and free tags as NPCs have, and the
 * values of the pinned release's sheet (field key to value, see CharacterValues), one per field. It
 * records the NPC it was promoted from, if any. The order of the values is not part of it: stored
 * JSONB does not keep one, so readers order them by the sheet.
 *
 * The rules are checked here, so a stored Character read back is checked too; whether the release
 * Tags exist and the values fit the sheet is the Campaign's check (ItemRules::assertInRelease(),
 * CharacterValues::resolve()).
 */
final readonly class Character
{
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param list<string>                                                         $tags
     * @param list<string>                                                         $freeTags
     * @param array<string, int|string|list<string>|array{current: int, max: int}> $values
     */
    private function __construct(
        private CharacterId $id,
        private string $name,
        private array $tags,
        private array $freeTags,
        private array $values,
        private ?NpcId $promotedFrom,
    ) {
    }

    /**
     * @param list<string> $tags     release Tag keys
     * @param list<string> $freeTags
     * @param array<mixed> $values   field key to value; every value well formed (CharacterValues::assertWellFormed())
     *
     * @throws InvalidCharacter when a value breaks a rule
     */
    public static function of(CharacterId $id, string $name, array $tags, array $freeTags, array $values, ?NpcId $promotedFrom = null): self
    {
        $invalid = InvalidCharacter::because(...);

        return new self(
            $id,
            ItemRules::name($name, self::MAX_NAME_LENGTH, $invalid),
            ItemRules::tags($tags, $invalid),
            ItemRules::freeTags($freeTags, $invalid),
            CharacterValues::assertWellFormed($values),
            $promotedFrom,
        );
    }

    /**
     * The same Character with new values, the NPC it came from kept.
     *
     * @param list<string> $tags
     * @param list<string> $freeTags
     * @param array<mixed> $values
     *
     * @throws InvalidCharacter when a value breaks a rule
     */
    public function edited(string $name, array $tags, array $freeTags, array $values): self
    {
        return self::of($this->id, $name, $tags, $freeTags, $values, $this->promotedFrom);
    }

    public function id(): CharacterId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string> release Tag keys
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return list<string>
     */
    public function freeTags(): array
    {
        return $this->freeTags;
    }

    /**
     * @return array<string, int|string|list<string>|array{current: int, max: int}> by field key
     */
    public function values(): array
    {
        return $this->values;
    }

    public function promotedFrom(): ?NpcId
    {
        return $this->promotedFrom;
    }
}
