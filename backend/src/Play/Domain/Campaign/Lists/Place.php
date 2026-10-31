<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * A site of a campaign (a town, a dungeon, a camp), with optional notes, free tags only the player
 * reads, and a weight (1 to 3) that will make a random pick favour it. Open while in play, closed
 * once cleared or lost. It may carry release Tags in its shape, but no release Tag applies to
 * Places yet (ADR 0047), so the Campaign refuses them.
 *
 * The rules are checked here, so a stored Place read back is checked too; whether the release Tags
 * exist in the pinned release is the Campaign's check (ItemRules::assertPlaceTags()).
 */
final readonly class Place
{
    public const int MAX_NAME_LENGTH = 200;

    public const int MAX_NOTES_LENGTH = 2000;

    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        private PlaceId $id,
        private string $name,
        private ?string $notes,
        private array $tags,
        private array $freeTags,
        private int $weight,
        private ItemStatus $status,
    ) {
    }

    /**
     * @param list<string> $tags     release Tag keys
     * @param list<string> $freeTags
     *
     * @throws InvalidPlace when a value breaks a rule
     */
    public static function of(PlaceId $id, string $name, ?string $notes, array $tags, array $freeTags, int $weight = ItemRules::DEFAULT_WEIGHT, ItemStatus $status = ItemStatus::Open): self
    {
        $invalid = InvalidPlace::because(...);

        $notes = null === $notes ? null : trim($notes);
        if ('' === $notes) {
            $notes = null;
        }

        if (null !== $notes && mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw InvalidPlace::because(\sprintf('The notes must be at most %d characters, got %d.', self::MAX_NOTES_LENGTH, mb_strlen($notes)));
        }

        return new self(
            $id,
            ItemRules::name($name, self::MAX_NAME_LENGTH, $invalid),
            $notes,
            ItemRules::tags($tags, $invalid),
            ItemRules::freeTags($freeTags, $invalid),
            ItemRules::weight($weight, $invalid),
            $status,
        );
    }

    /**
     * The same Place with new values, status kept.
     *
     * @param list<string> $tags
     * @param list<string> $freeTags
     *
     * @throws InvalidPlace when a value breaks a rule
     */
    public function edited(string $name, ?string $notes, array $tags, array $freeTags, int $weight): self
    {
        return self::of($this->id, $name, $notes, $tags, $freeTags, $weight, $this->status);
    }

    public function closed(): self
    {
        return new self($this->id, $this->name, $this->notes, $this->tags, $this->freeTags, $this->weight, ItemStatus::Closed);
    }

    public function reopened(): self
    {
        return new self($this->id, $this->name, $this->notes, $this->tags, $this->freeTags, $this->weight, ItemStatus::Open);
    }

    public function id(): PlaceId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function notes(): ?string
    {
        return $this->notes;
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

    public function weight(): int
    {
        return $this->weight;
    }

    public function status(): ItemStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return ItemStatus::Open === $this->status;
    }
}
