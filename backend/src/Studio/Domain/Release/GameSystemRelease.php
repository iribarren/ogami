<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * One immutable, published version of a GameSystem (ADR 0010): its validated content, the
 * release version Studio assigned and when it was published. There is no update path.
 *
 * State is kept as scalars and the canonical content array so persistence can map it without
 * custom types; content() rebuilds the value object.
 *
 * Release notes are what a game manager wrote when publishing from Studio. They are not part of the
 * content, so the Published Language and the content hash do not include them; releases published
 * from a file have none.
 */
final readonly class GameSystemRelease
{
    /** The longest release notes, in characters. */
    public const int MAX_NOTES_LENGTH = 2000;

    /**
     * @param array<string, mixed> $content canonical content, see ReleaseContent::toArray()
     */
    private function __construct(
        private string $id,
        private string $gameSystemKey,
        private int $version,
        private int $schemaVersion,
        private array $content,
        private string $contentHash,
        private \DateTimeImmutable $publishedAt,
        private ?string $notes,
    ) {
    }

    /**
     * @throws InvalidGameSystemRelease when the version is below 1, or the notes are blank or longer than MAX_NOTES_LENGTH
     */
    public static function publish(ReleaseId $id, ReleaseContent $content, int $version, \DateTimeImmutable $publishedAt, ?string $notes = null): self
    {
        if ($version < 1) {
            throw InvalidGameSystemRelease::versionBelowOne($version);
        }

        if (null !== $notes && '' === trim($notes)) {
            throw InvalidGameSystemRelease::notes('must not be blank');
        }

        if (null !== $notes && mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw InvalidGameSystemRelease::notes(\sprintf('must be at most %d characters', self::MAX_NOTES_LENGTH));
        }

        return new self(
            $id->toString(),
            $content->gameSystemKey(),
            $version,
            $content->schemaVersion(),
            $content->toArray(),
            $content->hash(),
            $publishedAt,
            $notes,
        );
    }

    public function id(): ReleaseId
    {
        return ReleaseId::fromString($this->id);
    }

    public function gameSystemKey(): string
    {
        return $this->gameSystemKey;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function content(): ReleaseContent
    {
        return ReleaseContent::fromArray($this->content);
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function publishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
