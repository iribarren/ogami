<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * A GameSystem as authored in Studio: the mutable draft behind its releases (ADR 0010, ADR 0028).
 * It belongs to the game manager who created it, and its key, which names the release line, never
 * changes.
 *
 * The draft is a release document (docs/contracts/gamesystem-release.md) kept as given, so it may be
 * invalid while work is in progress; ReleaseContent::fromArray() judges it when it is validated or
 * published. Studio's editors change it only through this aggregate, and every command is
 * all-or-nothing. State is scalars and plain arrays so persistence can map it without custom types.
 */
final class GameSystem
{
    /** The length of the owner_id column. */
    private const int OWNER_MAX_LENGTH = 255;

    /**
     * Concurrency token, owned by the persistence adapter: it counts saves so that a save of a
     * stale copy is refused (GameSystemModifiedConcurrently). The model never changes it.
     */
    private int $version = 1;

    /**
     * @param array<string, mixed> $document a release document: schemaVersion, gameSystem, sections
     */
    private function __construct(
        private readonly string $id,
        private readonly string $key,
        private readonly string $ownerId,
        private array $document,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * A schema version 2 draft with the empty sections of a minimal release.
     *
     * @throws InvalidGameSystemMetadata when the key, owner, name or description is invalid
     */
    public static function createBlank(GameSystemId $id, string $key, string $name, ?string $description, string $ownerId, \DateTimeImmutable $now): self
    {
        $key = self::validKey($key);
        $gameSystem = ['key' => $key, 'name' => GameSystemMetadata::name($name)];
        $description = GameSystemMetadata::description($description);
        if (null !== $description) {
            $gameSystem['description'] = $description;
        }

        return new self($id->toString(), $key, self::validOwner($ownerId), [
            'schemaVersion' => 2,
            'gameSystem' => $gameSystem,
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'sceneTypes' => [],
            'flows' => [],
            'sheet' => [],
            'checks' => [],
        ], $now, $now);
    }

    /**
     * A draft that copies a release document as published (its schema version, description,
     * credits, theme and sections) under a new key and name. A given description replaces the
     * source's; null keeps it.
     *
     * @param array<mixed> $sourceDocument a release document, e.g. ReleaseContent::toArray()
     *
     * @throws InvalidGameSystemMetadata when the key, owner, name or description is invalid, or the source is not a release document
     */
    public static function createFromRelease(GameSystemId $id, string $key, string $name, ?string $description, string $ownerId, array $sourceDocument, \DateTimeImmutable $now): self
    {
        $key = self::validKey($key);
        $name = GameSystemMetadata::name($name);
        $description = GameSystemMetadata::description($description);

        return new self($id->toString(), $key, self::validOwner($ownerId), self::withNameAndDescription(self::withKey(self::plain($sourceDocument), $key), $name, $description), $now, $now);
    }

    /**
     * Replaces name, description, credits and theme as a whole; nothing changes when one is refused.
     *
     * @throws InvalidGameSystemMetadata when credits or a theme is given for a schema version 1 draft
     */
    public function editMetadata(GameSystemMetadata $metadata, \DateTimeImmutable $now): void
    {
        if (1 === $this->schemaVersion()) {
            foreach (['credits' => $metadata->credits, 'theme' => $metadata->theme] as $field => $value) {
                if (null !== $value) {
                    throw InvalidGameSystemMetadata::at($field, 'not supported in schema version 1');
                }
            }
        }

        $document = $this->document;
        $gameSystem = ['key' => $this->key, 'name' => $metadata->name];
        if (null !== $metadata->description) {
            $gameSystem['description'] = $metadata->description;
        }

        if (null !== $metadata->credits) {
            $gameSystem['credits'] = $metadata->credits;
        }

        $document['gameSystem'] = $gameSystem;
        unset($document['theme']);
        if (null !== $metadata->theme) {
            $document['theme'] = $metadata->theme;
        }

        $this->document = $document;
        $this->updatedAt = $now;
    }

    /**
     * Replaces the draft with a release document as published; only the key stays this GameSystem's.
     *
     * @param array<mixed> $releaseDocument e.g. ReleaseContent::toArray() of a release of this GameSystem
     *
     * @throws InvalidGameSystemMetadata when the document is not a release document
     */
    public function restore(array $releaseDocument, \DateTimeImmutable $now): void
    {
        $this->document = self::withKey(self::plain($releaseDocument), $this->key);
        $this->updatedAt = $now;
    }

    public function id(): GameSystemId
    {
        return GameSystemId::fromString($this->id);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /**
     * @return array<string, mixed> the draft's release document, as stored (not necessarily valid)
     */
    public function document(): array
    {
        return $this->document;
    }

    public function schemaVersion(): int
    {
        /** @var int $version checked when the document entered */
        $version = $this->document['schemaVersion'];

        return $version;
    }

    /**
     * How many times this GameSystem was saved, plus one: the optimistic lock Doctrine maintains.
     */
    public function version(): int
    {
        return $this->version;
    }

    public function name(): string
    {
        /** @var array{name: string} $gameSystem checked when the document entered */
        $gameSystem = $this->document['gameSystem'];

        return $gameSystem['name'];
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @throws InvalidGameSystemMetadata
     */
    private static function validKey(string $key): string
    {
        try {
            return ReleaseFields::key($key, 'key');
        } catch (InvalidReleaseContent $rejection) {
            throw InvalidGameSystemMetadata::fromContract('key', $rejection);
        }
    }

    /**
     * @throws InvalidGameSystemMetadata
     */
    private static function validOwner(string $ownerId): string
    {
        if ('' === trim($ownerId)) {
            throw InvalidGameSystemMetadata::at('ownerId', 'must not be blank');
        }

        if (mb_strlen($ownerId) > self::OWNER_MAX_LENGTH || str_contains($ownerId, "\0")) {
            throw InvalidGameSystemMetadata::at('ownerId', \sprintf('must be at most %d characters, without NUL bytes', self::OWNER_MAX_LENGTH));
        }

        return $ownerId;
    }

    /**
     * Decoded JSON as plain arrays: an empty object (\stdClass) of a canonical release becomes [].
     *
     * @param array<mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function plain(array $document): array
    {
        /** @var array<string, mixed> $plain */
        $plain = json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return $plain;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed> the document with its GameSystem key set; the rest is kept as given
     *
     * @throws InvalidGameSystemMetadata when it has no supported schema version or no gameSystem object
     */
    private static function withKey(array $document, string $key): array
    {
        if (!\in_array($document['schemaVersion'] ?? null, ReleaseContent::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw InvalidGameSystemMetadata::at('schemaVersion', 'must be a supported schema version');
        }

        $gameSystem = $document['gameSystem'] ?? null;
        if (!\is_array($gameSystem) || array_is_list($gameSystem) || !\is_string($gameSystem['name'] ?? null)) {
            throw InvalidGameSystemMetadata::at('gameSystem', 'must be an object with a name');
        }

        $document['gameSystem'] = ['key' => $key, ...array_diff_key($gameSystem, ['key' => true])];

        return $document;
    }

    /**
     * @param array<string, mixed> $document a document checked by withKey()
     *
     * @return array<string, mixed> the document with this name, and this description when given (null keeps the document's)
     */
    private static function withNameAndDescription(array $document, string $name, ?string $description): array
    {
        /** @var array<string, mixed> $gameSystem */
        $gameSystem = $document['gameSystem'];
        $document['gameSystem'] = [...$gameSystem, 'name' => $name, ...null === $description ? [] : ['description' => $description]];

        return $document;
    }
}
