<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * A non-player character of a campaign, with an optional Agenda (what it wants), release Tags that
 * Flow steps pick and count it by, free tags only the player reads, and a weight (1 to 3) that
 * makes a random pick favour it. Open while in play, closed once set aside.
 *
 * The rules are checked here, so a stored NPC read back is checked too; whether the release Tags
 * exist in the pinned release is the Campaign's check (ItemRules::assertInRelease()).
 */
final readonly class Npc
{
    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_AGENDA_LENGTH = 2000;

    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        private NpcId $id,
        private string $name,
        private ?string $agenda,
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
     * @throws InvalidNpc when a value breaks a rule
     */
    public static function of(NpcId $id, string $name, ?string $agenda, array $tags, array $freeTags, int $weight = ItemRules::DEFAULT_WEIGHT, ItemStatus $status = ItemStatus::Open): self
    {
        $invalid = InvalidNpc::because(...);

        $agenda = null === $agenda ? null : trim($agenda);
        if ('' === $agenda) {
            $agenda = null;
        }

        if (null !== $agenda && mb_strlen($agenda) > self::MAX_AGENDA_LENGTH) {
            throw InvalidNpc::because(\sprintf('The agenda must be at most %d characters, got %d.', self::MAX_AGENDA_LENGTH, mb_strlen($agenda)));
        }

        return new self(
            $id,
            ItemRules::name($name, self::MAX_NAME_LENGTH, $invalid),
            $agenda,
            ItemRules::tags($tags, $invalid),
            ItemRules::freeTags($freeTags, $invalid),
            ItemRules::weight($weight, $invalid),
            $status,
        );
    }

    /**
     * The same NPC with new values, status kept.
     *
     * @param list<string> $tags
     * @param list<string> $freeTags
     *
     * @throws InvalidNpc when a value breaks a rule
     */
    public function edited(string $name, ?string $agenda, array $tags, array $freeTags, int $weight): self
    {
        return self::of($this->id, $name, $agenda, $tags, $freeTags, $weight, $this->status);
    }

    public function closed(): self
    {
        return new self($this->id, $this->name, $this->agenda, $this->tags, $this->freeTags, $this->weight, ItemStatus::Closed);
    }

    public function reopened(): self
    {
        return new self($this->id, $this->name, $this->agenda, $this->tags, $this->freeTags, $this->weight, ItemStatus::Open);
    }

    public function id(): NpcId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function agenda(): ?string
    {
        return $this->agenda;
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

    /**
     * @param list<string> $tags release Tag keys
     *
     * @return bool whether it carries all of them (any, when none are given)
     */
    public function carries(array $tags): bool
    {
        return [] === array_diff($tags, $this->tags);
    }
}
