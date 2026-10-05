<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * One immutable, published version of a GameSystem (ADR 0010): its validated content, the
 * release version Studio assigned and when it was published. There is no update path.
 *
 * State is kept as scalars and the canonical content array so persistence can map it without
 * custom types; content() rebuilds the value object.
 */
final readonly class GameSystemRelease
{
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
    ) {
    }

    /**
     * @throws InvalidGameSystemRelease when the version is below 1
     */
    public static function publish(ReleaseId $id, ReleaseContent $content, int $version, \DateTimeImmutable $publishedAt): self
    {
        if ($version < 1) {
            throw InvalidGameSystemRelease::versionBelowOne($version);
        }

        return new self(
            $id->toString(),
            $content->gameSystemKey(),
            $version,
            $content->schemaVersion(),
            $content->toArray(),
            $content->hash(),
            $publishedAt,
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
}
