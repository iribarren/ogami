<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\OraclesSection;
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
     * Creates an oracle table (no original key; it is appended) or replaces one by its original key
     * (it keeps its position; a different key renames it). The "oracles" section must stay valid for
     * the draft's schema version, checked by the publisher's own section validator, and so must the
     * draft by the item rules (entry sceneType and effects and the chaos tracker are references to
     * other sections). Nothing changes when a command is refused.
     *
     * @param array<array-key, mixed> $table
     *
     * @throws OracleNotFound        when the original key names no table
     * @throws OracleKeyTaken        when the new key belongs to another table or likelihood oracle
     * @throws OracleInUse           when a renamed table, or an entry key removed or renamed, is referenced
     * @throws InvalidReleaseContent when the section would be invalid, or is not well-formed
     */
    public function saveOracleTable(?string $originalKey, array $table, \DateTimeImmutable $now): void
    {
        $this->saveOracle('tables', $originalKey, $table, $now);
    }

    /**
     * @throws OracleNotFound        when no table has this key
     * @throws OracleInUse           when the draft references the table
     * @throws InvalidReleaseContent when the stored section is not well-formed
     */
    public function deleteOracleTable(string $key, \DateTimeImmutable $now): void
    {
        $this->deleteOracle('tables', $key, $now);
    }

    /**
     * Creates or replaces a likelihood oracle as saveOracleTable() does a table. Chaos "tracker" and
     * "randomEvent" are schema version 2 fields; a version 1 draft refuses them.
     *
     * @param array<array-key, mixed> $oracle
     *
     * @throws OracleNotFound        when the original key names no likelihood oracle
     * @throws OracleKeyTaken        when the new key belongs to another table or likelihood oracle
     * @throws OracleInUse           when a renamed likelihood oracle is referenced
     * @throws InvalidReleaseContent when the section would be invalid, or is not well-formed
     */
    public function saveLikelihoodOracle(?string $originalKey, array $oracle, \DateTimeImmutable $now): void
    {
        $this->saveOracle('likelihood', $originalKey, $oracle, $now);
    }

    /**
     * @throws OracleNotFound        when no likelihood oracle has this key
     * @throws OracleInUse           when the draft references the likelihood oracle
     * @throws InvalidReleaseContent when the stored section is not well-formed
     */
    public function deleteLikelihoodOracle(string $key, \DateTimeImmutable $now): void
    {
        $this->deleteOracle('likelihood', $key, $now);
    }

    /**
     * Creates a tracker, tag, fact slot, Scene Type or flow (no original key; it is appended) or
     * replaces one by its original key (it keeps its position; a different key renames it). The item
     * is stored as given once the whole draft passes the item rules (ADR 0030), so an item that
     * breaks them, or a change that breaks an item referencing it, is refused with the publisher's
     * path and nothing changes. A saved flow with "default" true clears it on the others.
     *
     * @param array<array-key, mixed> $item
     *
     * @throws NarrativeItemNotFound when the original key names no item of the section
     * @throws NarrativeKeyTaken     when the new key belongs to another item of the section
     * @throws NarrativeItemInUse    when a renamed tracker, tag, fact slot or Scene Type is referenced outside itself
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or a section is not well-formed
     */
    public function saveNarrativeItem(NarrativeSection $section, ?string $originalKey, array $item, \DateTimeImmutable $now): void
    {
        $items = $this->narrativeItems($section);
        $position = \count($items);
        if (null !== $originalKey) {
            $position = $this->indexOfKey($items, $originalKey) ?? throw NarrativeItemNotFound::withKey($section, $originalKey);
        }

        $key = $item['key'] ?? null;
        if (\is_string($key) && $key !== $originalKey && null !== $this->indexOfKey($items, $key)) {
            throw NarrativeKeyTaken::withKey($section, $key);
        }

        if (null !== $originalKey && $key !== $originalKey) {
            $this->refuseWhenNarrativeReferenced($section, $originalKey);
        }

        $items[$position] = $item;
        if (NarrativeSection::Flows === $section && true === ($item['default'] ?? null)) {
            foreach ($items as $index => $flow) {
                if ($index !== $position && \is_array($flow) && true === ($flow['default'] ?? null)) {
                    unset($flow['default']);
                    $items[$index] = $flow;
                }
            }
        }

        $this->replaceNarrativeItems($section, array_values($items), $now);
    }

    /**
     * @throws NarrativeItemNotFound when the section has no item with this key
     * @throws NarrativeItemInUse    when the draft references a tracker, tag, fact slot or Scene Type outside itself
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or a section is not well-formed
     */
    public function deleteNarrativeItem(NarrativeSection $section, string $key, \DateTimeImmutable $now): void
    {
        $items = $this->narrativeItems($section);
        $position = $this->indexOfKey($items, $key) ?? throw NarrativeItemNotFound::withKey($section, $key);
        $this->refuseWhenNarrativeReferenced($section, $key);

        unset($items[$position]);
        $this->replaceNarrativeItems($section, array_values($items), $now);
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
     * @param 'tables'|'likelihood'   $section
     * @param array<array-key, mixed> $item
     */
    private function saveOracle(string $section, ?string $originalKey, array $item, \DateTimeImmutable $now): void
    {
        $oracles = $this->oraclesSection();
        /** @var list<mixed> $items */
        $items = $oracles[$section];
        $position = \count($items);
        if (null !== $originalKey) {
            $position = $this->indexOfKey($items, $originalKey) ?? throw OracleNotFound::withKey($originalKey);
        }

        $key = $item['key'] ?? null;
        if (\is_string($key) && $key !== $originalKey && (null !== $this->indexOfKey($oracles['tables'], $key) || null !== $this->indexOfKey($oracles['likelihood'], $key))) {
            throw OracleKeyTaken::withKey($key);
        }

        if (null !== $originalKey) {
            if ($key !== $originalKey) {
                $this->refuseWhenReferenced($section, $originalKey);
            }

            if ('tables' === $section) {
                $this->refuseWhenEntryKeysAreReferenced($originalKey, $items[$position], $item);
            }
        }

        $oracles[$section][$position] = $item;
        $validated = OraclesSection::validate($oracles, $this->schemaVersion());
        $oracles[$section][$position] = $validated[$section][$position];
        $this->replaceOracles($oracles, $now);
    }

    /**
     * @param 'tables'|'likelihood' $section
     */
    private function deleteOracle(string $section, string $key, \DateTimeImmutable $now): void
    {
        $oracles = $this->oraclesSection();
        $position = $this->indexOfKey($oracles[$section], $key) ?? throw OracleNotFound::withKey($key);
        $this->refuseWhenReferenced($section, $key);

        unset($oracles[$section][$position]);
        $oracles[$section] = array_values($oracles[$section]);
        OraclesSection::validate($oracles, $this->schemaVersion());
        $this->replaceOracles($oracles, $now);
    }

    /**
     * @return list<mixed> the stored items of the section; none when it is missing
     *
     * @throws InvalidReleaseContent when the draft is schema version 1 or the section is not a list
     */
    private function narrativeItems(NarrativeSection $section): array
    {
        if (1 === $this->schemaVersion()) {
            throw InvalidReleaseContent::at('schemaVersion', 'narrative sections need schema version 2, this draft is version 1');
        }

        return ReleaseFields::list($this->document[$section->value] ?? [], $section->value);
    }

    /**
     * @param list<mixed> $items
     *
     * @throws InvalidReleaseContent when the new document breaks the item rules
     */
    private function replaceNarrativeItems(NarrativeSection $section, array $items, \DateTimeImmutable $now): void
    {
        $document = $this->document;
        $document[$section->value] = $items;
        ReleaseContent::checkItemRules($document);
        $this->document = $document;
        $this->updatedAt = $now;
    }

    /**
     * @throws NarrativeItemInUse when the draft names this item outside the item itself
     */
    private function refuseWhenNarrativeReferenced(NarrativeSection $section, string $key): void
    {
        $paths = DraftReferences::narrativePathsTo($this->document, $section, $key);
        if ([] !== $paths) {
            throw NarrativeItemInUse::at(\sprintf('The %s "%s"', $section->noun(), $key), $paths);
        }
    }

    /**
     * @param 'tables'|'likelihood' $section
     *
     * @throws OracleInUse when the draft names this oracle (a table's own entries do not count)
     */
    private function refuseWhenReferenced(string $section, string $key): void
    {
        $paths = DraftReferences::oraclePathsTo($this->document, $key, 'tables' === $section ? $key : null);
        if ([] !== $paths) {
            throw OracleInUse::at(\sprintf('The oracle "%s"', $key), $paths);
        }
    }

    /**
     * @param mixed                   $stored the table as stored
     * @param array<array-key, mixed> $saved  the table as given
     *
     * @throws OracleInUse when a table step branch names an entry key the saved table no longer has; lists every such branch
     */
    private function refuseWhenEntryKeysAreReferenced(string $table, mixed $stored, array $saved): void
    {
        $kept = $this->entryKeys($saved);
        $dropped = [];
        $paths = [];
        foreach ($this->entryKeys(\is_array($stored) ? $stored : []) as $entryKey) {
            $entryPaths = \in_array($entryKey, $kept, true) ? [] : DraftReferences::entryPathsTo($this->document, $table, $entryKey);
            if ([] !== $entryPaths) {
                $dropped[] = \sprintf('"%s"', $entryKey);
                $paths = [...$paths, ...$entryPaths];
            }
        }

        if ([] !== $paths) {
            throw OracleInUse::at(\sprintf('The %s of table "%s"', 1 === \count($dropped) ? 'entry '.$dropped[0] : 'entries '.implode(', ', $dropped), $table), $paths);
        }
    }

    /**
     * @param array<array-key, mixed> $table
     *
     * @return list<string> the keys of its entries, skipping what is not a string key
     */
    private function entryKeys(array $table): array
    {
        $entries = \is_array($table['entries'] ?? null) ? $table['entries'] : [];
        $keys = [];
        foreach ($entries as $entry) {
            if (\is_array($entry) && \is_string($entry['key'] ?? null)) {
                $keys[] = $entry['key'];
            }
        }

        return $keys;
    }

    /**
     * @param list<mixed> $items
     */
    private function indexOfKey(array $items, string $key): ?int
    {
        foreach ($items as $index => $item) {
            if (\is_array($item) && ($item['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The stored "oracles" section, which must at least be an object of two lists.
     *
     * @return array{tables: list<mixed>, likelihood: list<mixed>}
     *
     * @throws InvalidReleaseContent
     */
    private function oraclesSection(): array
    {
        $oracles = $this->document['oracles'] ?? null;
        if (!\is_array($oracles) || array_is_list($oracles) && [] !== $oracles) {
            throw InvalidReleaseContent::at('oracles', 'must be an object');
        }

        /** @var array{tables: list<mixed>, likelihood: list<mixed>} $section */
        $section = [
            ...$oracles,
            'tables' => ReleaseFields::list($oracles['tables'] ?? null, 'oracles.tables'),
            'likelihood' => ReleaseFields::list($oracles['likelihood'] ?? null, 'oracles.likelihood'),
        ];

        return $section;
    }

    /**
     * @param array<array-key, mixed> $oracles
     */
    private function replaceOracles(array $oracles, \DateTimeImmutable $now): void
    {
        $document = $this->document;
        $document['oracles'] = $oracles;
        // The section is valid on its own; entry sceneType and effects and the chaos tracker also need the other sections (ADR 0030).
        ReleaseContent::checkItemRules($document);
        $this->document = $document;
        $this->updatedAt = $now;
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
