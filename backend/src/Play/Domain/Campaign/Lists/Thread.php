<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * A storyline of a campaign, with release Tags that
 * Flow steps pick and count it by, free tags only the player reads, and a weight (1 to 3) that
 * makes a random pick favour it. Open while in play, closed once set aside.
 *
 * The rules are checked here, so a stored Thread read back is checked too; whether the release Tags
 * exist in the pinned release is the Campaign's check (ItemRules::assertInRelease()).
 */
final readonly class Thread
{
    public const int MAX_NAME_LENGTH = 200;

    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        private ThreadId $id,
        private string $name,
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
     * @throws InvalidThread when a value breaks a rule
     */
    public static function of(ThreadId $id, string $name, array $tags, array $freeTags, int $weight = ItemRules::DEFAULT_WEIGHT, ItemStatus $status = ItemStatus::Open): self
    {
        $invalid = InvalidThread::because(...);

        return new self(
            $id,
            ItemRules::name($name, self::MAX_NAME_LENGTH, $invalid),
            ItemRules::tags($tags, $invalid),
            ItemRules::freeTags($freeTags, $invalid),
            ItemRules::weight($weight, $invalid),
            $status,
        );
    }

    /**
     * The same Thread with new values, status kept.
     *
     * @param list<string> $tags
     * @param list<string> $freeTags
     *
     * @throws InvalidThread when a value breaks a rule
     */
    public function edited(string $name, array $tags, array $freeTags, int $weight): self
    {
        return self::of($this->id, $name, $tags, $freeTags, $weight, $this->status);
    }

    public function closed(): self
    {
        return new self($this->id, $this->name, $this->tags, $this->freeTags, $this->weight, ItemStatus::Closed);
    }

    public function reopened(): self
    {
        return new self($this->id, $this->name, $this->tags, $this->freeTags, $this->weight, ItemStatus::Open);
    }

    public function id(): ThreadId
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
