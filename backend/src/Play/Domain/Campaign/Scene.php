<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A unit of story within a session. Numbered from 1 within its session; its identity is the
 * campaign, the session number and its own number. Immutable once started.
 */
final readonly class Scene
{
    public const int MAX_TITLE_LENGTH = 100;

    private function __construct(
        private int $number,
        private string $title,
        private \DateTimeImmutable $startedAt,
    ) {
    }

    /**
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public static function start(int $number, string $title, \DateTimeImmutable $startedAt): self
    {
        $title = trim($title);
        if ('' === $title) {
            throw InvalidSceneTitle::blank();
        }

        $length = mb_strlen($title);
        if ($length > self::MAX_TITLE_LENGTH) {
            throw InvalidSceneTitle::tooLong(self::MAX_TITLE_LENGTH, $length);
        }

        return new self($number, $title, $startedAt);
    }

    /**
     * Rebuilds a stored scene, e.g. from persistence. No rule is checked again.
     */
    public static function reconstitute(int $number, string $title, \DateTimeImmutable $startedAt): self
    {
        return new self($number, $title, $startedAt);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
}
