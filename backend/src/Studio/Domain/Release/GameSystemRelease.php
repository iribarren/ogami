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
 *
 * The domain refuses a superset of what the database CHECK refuses (chk_studio_gamesystem_release_notes:
 * longer than 2,000 characters, or only ASCII whitespace), so a flush never fails on notes the domain
 * accepted. Beyond the CHECK, the domain also refuses notes that are only Unicode whitespace (NBSP,
 * U+2003, U+3000...), which are blank to a reader, and notes Postgres TEXT cannot store: invalid UTF-8
 * and NUL.
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
     * @throws InvalidGameSystemRelease when the version is below 1, or the notes are blank, longer than MAX_NOTES_LENGTH, not valid UTF-8 or contain NUL
     */
    public static function publish(ReleaseId $id, ReleaseContent $content, int $version, \DateTimeImmutable $publishedAt, ?string $notes = null): self
    {
        if ($version < 1) {
            throw InvalidGameSystemRelease::versionBelowOne($version);
        }

        if (null !== $notes) {
            self::assertValidNotes($notes);
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

    private static function assertValidNotes(string $notes): void
    {
        if (!mb_check_encoding($notes, 'UTF-8')) {
            throw InvalidGameSystemRelease::notes('must be valid UTF-8');
        }

        if (str_contains($notes, "\0")) {
            throw InvalidGameSystemRelease::notes('must not contain a NUL character');
        }

        // Blank is whitespace and Unicode separators only; \s alone misses NBSP and the like.
        if (1 === preg_match('/^[\s\p{Z}]*$/u', $notes)) {
            throw InvalidGameSystemRelease::notes('must not be blank');
        }

        if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw InvalidGameSystemRelease::notes(\sprintf('must be at most %d characters', self::MAX_NOTES_LENGTH));
        }
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
