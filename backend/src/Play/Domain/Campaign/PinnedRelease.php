<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * The GameSystem release a campaign plays on (ADR 0014). Releases are immutable, so the pin is a
 * reference (key and version), not a copy of the content; Play re-reads the snapshot through its
 * anti-corruption layer. The GameSystem name is kept for display only.
 */
final readonly class PinnedRelease
{
    private function __construct(
        private string $gameSystemKey,
        private int $releaseVersion,
        private string $gameSystemName,
    ) {
    }

    /**
     * @throws InvalidPinnedRelease
     */
    public static function of(string $gameSystemKey, int $releaseVersion, string $gameSystemName): self
    {
        if ('' === trim($gameSystemKey)) {
            throw InvalidPinnedRelease::blankKey();
        }

        if ($releaseVersion < 1) {
            throw InvalidPinnedRelease::versionBelowOne($releaseVersion);
        }

        if ('' === trim($gameSystemName)) {
            throw InvalidPinnedRelease::blankName();
        }

        return new self($gameSystemKey, $releaseVersion, $gameSystemName);
    }

    public function gameSystemKey(): string
    {
        return $this->gameSystemKey;
    }

    public function releaseVersion(): int
    {
        return $this->releaseVersion;
    }

    public function gameSystemName(): string
    {
        return $this->gameSystemName;
    }

    /**
     * Same release: key and version. The name is display data.
     */
    public function equals(self $other): bool
    {
        return $this->gameSystemKey === $other->gameSystemKey && $this->releaseVersion === $other->releaseVersion;
    }
}
