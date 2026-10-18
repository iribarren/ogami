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

    /** The group declaring a layout gives the fields that have none. */
    private const string DEFAULT_SHEET_GROUP = 'General';

    /**
     * Concurrency token, owned by the persistence adapter: it counts saves so that a save of a
     * stale copy is refused (GameSystemModifiedConcurrently). The model never changes it.
     */
    private int $version = 1;

    /**
     * What the draft imported from the library, kept outside the document (ADR 0033), as stored:
     * plain arrays, replaced as a whole so persistence sees the change. libraryImports() reads them
     * as values.
     *
     * @var list<array<string, mixed>>
     */
    private array $libraryImports = [];

    /**
     * @param array<string, mixed> $document a release document: schemaVersion, gameSystem, sections
     */
    private function __construct(
        private readonly string $id,
        private readonly string $key,
        private readonly string $ownerId,
        private readonly GameSystemKind $kind,
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
        return self::blank(GameSystemKind::GameSystem, $id, $key, $name, $description, $ownerId, $now);
    }

    /**
     * A library pack: blank like createBlank(), and like it schema version 2, with no theme or
     * credits. It holds oracles, trackers, tags, fact slots and Scene Types, and refuses the rest
     * with LibraryPackRefuses (ADR 0033).
     *
     * @throws InvalidGameSystemMetadata when the key, owner, name or description is invalid
     */
    public static function createLibraryPack(GameSystemId $id, string $key, string $ownerId, string $name, ?string $description, \DateTimeImmutable $now): self
    {
        return self::blank(GameSystemKind::LibraryPack, $id, $key, $name, $description, $ownerId, $now);
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

        return new self($id->toString(), $key, self::validOwner($ownerId), GameSystemKind::GameSystem, self::withNameAndDescription(self::withKey(self::plain($sourceDocument), $key), $name, $description), $now, $now);
    }

    /**
     * Replaces name, description, credits and theme as a whole; nothing changes when one is refused.
     *
     * @throws InvalidGameSystemMetadata when credits or a theme is given for a schema version 1 draft
     */
    public function editMetadata(GameSystemMetadata $metadata, \DateTimeImmutable $now): void
    {
        if (null !== $metadata->credits) {
            $this->refuseOnPack('have credits');
        }

        if (null !== $metadata->theme) {
            $this->refuseOnPack('have a theme');
        }

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

        $this->commit($document, $now);
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
        if (NarrativeSection::Flows === $section) {
            $this->refuseOnPack('save a flow');
        }

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
        if (NarrativeSection::Flows === $section) {
            $this->refuseOnPack('delete a flow');
        }

        $items = $this->narrativeItems($section);
        $position = $this->indexOfKey($items, $key) ?? throw NarrativeItemNotFound::withKey($section, $key);
        $this->refuseWhenNarrativeReferenced($section, $key);

        unset($items[$position]);
        $this->replaceNarrativeItems($section, array_values($items), $now);
    }

    /**
     * Creates a sheet field (no original key; it is appended) or replaces one by its original key (it
     * keeps its position; a different key renames it). The field is stored as given once the whole
     * draft passes the item rules (ADR 0030), so a type change that breaks a formula or a sheet
     * effect, or a layout rule, is refused with the publisher's path and nothing changes. The
     * sheet's columns and groups stay as they are; a draft without a sheet or fields gets them.
     *
     * @param array<array-key, mixed> $field
     *
     * @throws SheetFieldNotFound    when the original key names no field
     * @throws SheetFieldKeyTaken    when the new key belongs to another field
     * @throws SheetFieldInUse       when a renamed field is referenced outside itself
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or the sheet is not well-formed
     */
    public function saveSheetField(?string $originalKey, array $field, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('save a sheet field');
        $fields = $this->sheetFields();
        $position = \count($fields);
        if (null !== $originalKey) {
            $position = $this->indexOfKey($fields, $originalKey) ?? throw SheetFieldNotFound::withKey($originalKey);
        }

        $key = $field['key'] ?? null;
        if (\is_string($key) && $key !== $originalKey && null !== $this->indexOfKey($fields, $key)) {
            throw SheetFieldKeyTaken::withKey($key);
        }

        if (null !== $originalKey && $key !== $originalKey) {
            $this->refuseWhenSheetFieldReferenced($originalKey);
        }

        $fields[$position] = $field;
        $this->replaceSheetFields(array_values($fields), $now);
    }

    /**
     * @throws SheetFieldNotFound    when the sheet has no field with this key
     * @throws SheetFieldInUse       when the draft references the field outside itself
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or the sheet is not well-formed
     */
    public function deleteSheetField(string $key, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('delete a sheet field');
        $fields = $this->sheetFields();
        $position = $this->indexOfKey($fields, $key) ?? throw SheetFieldNotFound::withKey($key);
        $this->refuseWhenSheetFieldReferenced($key);

        unset($fields[$position]);
        $this->replaceSheetFields(array_values($fields), $now);
    }

    /**
     * Moves a field to a position of the whole field list and changes nothing else; its group
     * changes through saveSheetField().
     *
     * @param int $index the target position, 0 to the number of fields minus one
     *
     * @throws SheetFieldNotFound    when the sheet has no field with this key
     * @throws InvalidReleaseContent when the index is out of range, the draft is schema version 1, the draft would break the item rules, or the sheet is not well-formed
     */
    public function moveSheetField(string $key, int $index, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('move a sheet field');
        $fields = $this->sheetFields();
        $position = $this->indexOfKey($fields, $key) ?? throw SheetFieldNotFound::withKey($key);
        if ($index < 0 || $index >= \count($fields)) {
            throw InvalidReleaseContent::at('index', \sprintf('must be between 0 and %d, %d given', \count($fields) - 1, $index));
        }

        $moved = array_splice($fields, $position, 1);
        array_splice($fields, $index, 0, $moved);
        $this->replaceSheetFields($fields, $now);
    }

    /**
     * One saved check as stored, for a test roll to read.
     *
     * @return array<array-key, mixed>
     *
     * @throws CheckNotFound         when the draft has no check with this key
     * @throws InvalidReleaseContent when the draft is schema version 1 or "checks" is not well-formed
     */
    public function check(string $key): array
    {
        $checks = $this->checks();
        $check = $checks[$this->indexOfKey($checks, $key) ?? throw CheckNotFound::withKey($key)];
        \assert(\is_array($check));

        return $check;
    }

    /**
     * Creates a check (no original key; it is appended) or replaces one by its original key (it
     * keeps its position; a different key renames it). The check is stored as given once the whole
     * draft passes the item rules (ADR 0032), so a dice notation, formula, difficulty or band that
     * the publisher refuses, or a check step that the change would break, is refused with the
     * publisher's path and nothing changes. A draft without checks gets them.
     *
     * @param array<array-key, mixed> $check
     *
     * @throws CheckNotFound         when the original key names no check
     * @throws CheckKeyTaken         when the new key belongs to another check
     * @throws CheckInUse            when a renamed check is named by a check step, or the check drops a band that an outcome names
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or "checks" is not well-formed
     */
    public function saveCheck(?string $originalKey, array $check, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('save a check');
        $checks = $this->checks();
        $position = \count($checks);
        if (null !== $originalKey) {
            $position = $this->indexOfKey($checks, $originalKey) ?? throw CheckNotFound::withKey($originalKey);
        }

        $key = $check['key'] ?? null;
        if (\is_string($key) && $key !== $originalKey && null !== $this->indexOfKey($checks, $key)) {
            throw CheckKeyTaken::withKey($key);
        }

        if (null !== $originalKey && \is_string($key)) {
            if ($key !== $originalKey) {
                $this->refuseWhenCheckReferenced($originalKey);
            }

            $this->refuseWhenBandsAreReferenced($originalKey, $checks[$position], $check);
        }

        $checks[$position] = $check;
        $this->replaceChecks(array_values($checks), $now);
    }

    /**
     * @throws CheckNotFound         when the draft has no check with this key
     * @throws CheckInUse            when a check step names the check
     * @throws InvalidReleaseContent when the draft is schema version 1, the draft would break the item rules, or "checks" is not well-formed
     */
    public function deleteCheck(string $key, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('delete a check');
        $checks = $this->checks();
        $position = $this->indexOfKey($checks, $key) ?? throw CheckNotFound::withKey($key);
        $this->refuseWhenCheckReferenced($key);

        unset($checks[$position]);
        $this->replaceChecks(array_values($checks), $now);
    }

    /**
     * Moves a check to a position of the whole check list and changes nothing else.
     *
     * @param int $index the target position, 0 to the number of checks minus one
     *
     * @throws CheckNotFound         when the draft has no check with this key
     * @throws InvalidReleaseContent when the index is out of range, the draft is schema version 1, the draft would break the item rules, or "checks" is not well-formed
     */
    public function moveCheck(string $key, int $index, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('move a check');
        $checks = $this->checks();
        $position = $this->indexOfKey($checks, $key) ?? throw CheckNotFound::withKey($key);
        if ($index < 0 || $index >= \count($checks)) {
            throw InvalidReleaseContent::at('index', \sprintf('must be between 0 and %d, %d given', \count($checks) - 1, $index));
        }

        $moved = array_splice($checks, $position, 1);
        array_splice($checks, $index, 0, $moved);
        $this->replaceChecks($checks, $now);
    }

    /**
     * Replaces the declared groups, the sheet's columns and the groups' columns and spans in one go.
     * A group is identified by its "previousName" when it has one, else by its name, so it may be
     * renamed (every field of it follows, all renames at once, so two groups can swap names),
     * reordered or kept; a group nobody names is dropped, which is refused while it holds fields.
     * "previousName" is not stored. The range, uniqueness and span rules are the item rules' (ADR 0031).
     *
     * @param array<array-key, mixed> $groups
     *
     * @throws InvalidReleaseContent when the draft is schema version 1 or declares no groups, a group is not an object or names a missing one, one is dropped with fields, the draft would break the item rules, or the sheet is not well-formed
     */
    public function saveSheetLayout(mixed $columns, array $groups, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('save a sheet layout');
        $fields = $this->sheetFields();
        $sheet = $this->sheet();
        $declared = $this->declaredGroupNames($sheet);
        if ([] === $declared) {
            throw InvalidReleaseContent::at('sheet.groups', 'no layout is declared, declare the layout first');
        }

        if ([] === $groups) {
            throw InvalidReleaseContent::at('groups', 'must not be empty, use DELETE /sheet/layout to remove the layout');
        }

        if (!array_is_list($groups)) {
            throw InvalidReleaseContent::at('groups', 'must be a list');
        }

        $renames = [];
        $kept = [];
        $stored = [];
        foreach ($groups as $index => $group) {
            $path = \sprintf('groups[%d]', $index);
            if (!\is_array($group) || [] !== $group && array_is_list($group)) {
                throw InvalidReleaseContent::at($path, 'must be an object');
            }

            $name = $group['name'] ?? null;
            if (!\is_string($name)) {
                throw InvalidReleaseContent::at($path.'.name', 'must be a text');
            }

            $current = $this->currentGroupOf($group, $name, $declared, $path);
            if (null !== $current) {
                if (isset($kept[$current])) {
                    throw InvalidReleaseContent::at($path, \sprintf('names the group "%s" like groups[%d]', $current, $kept[$current]));
                }

                $kept[$current] = $index;
                if ($current !== $name) {
                    $renames[$current] = $name;
                }
            }

            $stored[] = array_filter(array_diff_key($group, ['previousName' => true]), static fn (mixed $value, string|int $property): bool => null !== $value && (1 !== $value || 'columns' !== $property && 'span' !== $property), \ARRAY_FILTER_USE_BOTH);
        }

        foreach ($declared as $name) {
            $held = \count(array_filter($fields, static fn (mixed $field): bool => \is_array($field) && ($field['group'] ?? null) === $name));
            if (!isset($kept[$name]) && $held > 0) {
                throw InvalidReleaseContent::at('groups', \sprintf('cannot drop the group "%s", it holds %d %s, move or delete its fields first', $name, $held, 1 === $held ? 'field' : 'fields'));
            }
        }

        unset($sheet['columns']);
        if (null !== $columns && 1 !== $columns) {
            $sheet['columns'] = $columns;
        }

        $sheet['groups'] = $stored;
        $sheet['fields'] = array_map(static fn (mixed $field): mixed => \is_array($field) && \is_string($field['group'] ?? null) && isset($renames[$field['group']]) ? [...$field, 'group' => $renames[$field['group']]] : $field, $fields);
        $this->replaceSheet($sheet, $now);
    }

    /**
     * Turns a sheet without declared groups into one with them: a group for each distinct field group
     * in order of first appearance (a field without one belongs to "General"), every field naming its
     * group, fields reordered so each group is contiguous (their relative order is kept). One column,
     * no spans.
     *
     * @throws InvalidReleaseContent when the draft is schema version 1, already declares groups, has no fields, the draft would break the item rules, or the sheet is not well-formed
     */
    public function declareSheetLayout(\DateTimeImmutable $now): void
    {
        $this->refuseOnPack('declare a sheet layout');
        $fields = $this->sheetFields();
        $sheet = $this->sheet();
        if ([] !== $this->declaredGroupNames($sheet)) {
            throw InvalidReleaseContent::at('sheet.groups', 'already declared');
        }

        if ([] === $fields) {
            throw InvalidReleaseContent::at('sheet.fields', 'add a field first, there is nothing to group');
        }

        $grouped = [];
        foreach ($fields as $index => $field) {
            if (!\is_array($field)) {
                throw InvalidReleaseContent::at(\sprintf('sheet.fields[%d]', $index), 'must be an object');
            }

            $group = $field['group'] ?? self::DEFAULT_SHEET_GROUP;
            if (!\is_string($group)) {
                throw InvalidReleaseContent::at(\sprintf('sheet.fields[%d].group', $index), 'must be a text');
            }

            $grouped[$group][] = [...$field, 'group' => $group];
        }

        $sheet['groups'] = array_map(static fn (int|string $name): array => ['name' => (string) $name], array_keys($grouped));
        $this->replaceSheet([...$sheet, 'fields' => array_merge(...array_values($grouped))], $now);
    }

    /**
     * Back to plain groups: drops the sheet's columns, the declared groups and every field's span;
     * the fields keep their group and order.
     *
     * @throws InvalidReleaseContent when the draft is schema version 1, declares no groups, the draft would break the item rules, or the sheet is not well-formed
     */
    public function removeSheetLayout(\DateTimeImmutable $now): void
    {
        $this->refuseOnPack('remove a sheet layout');
        $fields = $this->sheetFields();
        $sheet = $this->sheet();
        if ([] === $this->declaredGroupNames($sheet)) {
            throw InvalidReleaseContent::at('sheet.groups', 'no declared layout, there is nothing to remove');
        }

        unset($sheet['columns'], $sheet['groups']);
        $sheet['fields'] = array_map(static fn (mixed $field): mixed => \is_array($field) ? array_diff_key($field, ['span' => true]) : $field, $fields);
        $this->replaceSheet($sheet, $now);
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
        $this->refuseOnPack('restore a release');
        $this->commit(self::withKey(self::plain($releaseDocument), $this->key), $now);
    }

    /**
     * What importing these items of a pack would do: see importFromLibrary(), which refuses what
     * the plan reports as a conflict or an error. Nothing changes.
     *
     * @param list<LibraryItemRef> $items
     *
     * @throws LibraryPackRefuses        when this draft is a library pack
     * @throws \InvalidArgumentException when $pack is not a library pack
     * @throws InvalidReleaseContent     when the draft is schema version 1 or the items are empty
     * @throws LibraryItemNotFound       when the pack has none of an item
     */
    public function planLibraryImport(self $pack, array $items): LibraryImportPlan
    {
        [$plan, $document] = $this->resolveLibraryImport($pack, $items);
        if ([] !== $plan->conflicts) {
            return $plan;
        }

        try {
            ReleaseContent::checkItemRules($document);
        } catch (InvalidReleaseContent $invalid) {
            [$path, $message] = explode(': ', $invalid->getMessage(), 2) + [1 => ''];

            return $plan->withError($path, $message);
        }

        return $plan;
    }

    /**
     * Copies items of a library pack, and the pack items they need, into the draft (ADR 0033). A
     * needed item the draft already has stays as it is (override); a requested item the draft
     * already has, imported or authored, refuses the import. Copies are appended to their sections
     * in pack order, the item rules judge the result, and each copy is remembered with the pack
     * and the hash of the pack item. Nothing changes when it is refused.
     *
     * @param list<LibraryItemRef> $items
     *
     * @throws LibraryPackRefuses        when this draft is a library pack
     * @throws \InvalidArgumentException when $pack is not a library pack
     * @throws LibraryItemNotFound       when the pack has none of an item
     * @throws LibraryItemKeyTaken       when the draft has requested items; lists every one
     * @throws InvalidReleaseContent     when the draft is schema version 1, the items are empty or the result would break the item rules
     */
    public function importFromLibrary(self $pack, array $items, \DateTimeImmutable $now): void
    {
        [$plan, $document] = $this->resolveLibraryImport($pack, $items);
        if ([] !== $plan->conflicts) {
            throw LibraryItemKeyTaken::for($plan->conflicts);
        }

        ReleaseContent::checkItemRules($document);
        $this->commit($document, $now, [...$this->libraryImports(), ...$this->importsOf($pack, $plan->imports)]);
    }

    /**
     * Replaces an imported item, in place, with the pack's current one, and imports the pack items
     * it now needs that the draft lacks (needed items the draft has are overrides, as in
     * importFromLibrary()). The item rules judge the result; nothing changes when it is refused.
     *
     * @throws LibraryPackRefuses        when this draft is a library pack
     * @throws \InvalidArgumentException when $pack is not a library pack
     * @throws InvalidReleaseContent     when the draft is schema version 1 or the result would break the item rules
     * @throws LibraryImportNotFound     when the item was not imported, or not from this pack
     * @throws LibraryItemRemoved        when the pack no longer has the item
     */
    public function updateFromLibrary(self $pack, LibrarySection $section, string $key, \DateTimeImmutable $now): void
    {
        $this->assertLibraryImportable($pack, 'update from the library');
        $ref = new LibraryItemRef($section, $key);
        $import = $this->libraryImport($ref) ?? throw LibraryImportNotFound::for($ref);
        if ($import->pack !== $pack->key) {
            throw LibraryImportNotFound::for($ref, $pack->key);
        }

        $source = LibraryItems::of($pack->document);
        $latest = $source->find($ref) ?? throw LibraryItemRemoved::from($ref, $pack->key);
        $own = LibraryItems::of($this->document)->find($ref) ?? throw LibraryImportNotFound::for($ref);
        [$closure] = $source->closure([$ref], LibraryItems::of($this->document));
        $added = array_values(array_filter($closure, static fn (LibraryItemRef $item): bool => !$item->is($ref)));

        $document = $own['list'] === $latest['list']
            ? LibraryItems::replaced($this->document, $own['list'], $own['index'], $latest['item'])
            : LibraryItems::appended(LibraryItems::removed($this->document, $own['list'], $own['index']), $latest['list'], $latest['item']);
        $document = $this->withCopies($document, $source, $added);
        ReleaseContent::checkItemRules($document);

        $imports = array_map(static fn (LibraryImport $stored): LibraryImport => $stored->ref()->is($ref) ? new LibraryImport($section, $key, $pack->key, ReleaseContent::itemHash($latest['item'])) : $stored, $this->libraryImports());
        $this->commit($document, $now, [...$imports, ...$this->importsOf($pack, $added)]);
    }

    /**
     * Forgets that an item came from the library; the item stays a plain item of the draft.
     *
     * @throws LibraryPackRefuses    when this draft is a library pack
     * @throws LibraryImportNotFound when the item was not imported
     */
    public function detachLibraryImport(LibrarySection $section, string $key, \DateTimeImmutable $now): void
    {
        $this->refuseOnPack('detach library imports');
        $ref = new LibraryItemRef($section, $key);
        if (!$this->libraryImport($ref) instanceof LibraryImport) {
            throw LibraryImportNotFound::for($ref);
        }

        $this->commit($this->document, $now, array_values(array_filter($this->libraryImports(), static fn (LibraryImport $import): bool => !$import->ref()->is($ref))));
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

    public function kind(): GameSystemKind
    {
        return $this->kind;
    }

    public function isLibraryPack(): bool
    {
        return GameSystemKind::LibraryPack === $this->kind;
    }

    /**
     * @return list<LibraryImport> what the draft imported from the library, in the order imported
     *
     * @throws \UnexpectedValueException when the stored imports are malformed
     */
    public function libraryImports(): array
    {
        return LibraryImport::listFromStored($this->libraryImports);
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
     * @return list<mixed> the stored fields of the sheet; none when it has none
     *
     * @throws InvalidReleaseContent when the draft is schema version 1 or the sheet or its fields are not well-formed
     */
    private function sheetFields(): array
    {
        if (1 === $this->schemaVersion()) {
            throw InvalidReleaseContent::at('schemaVersion', 'the sheet needs schema version 2, this draft is version 1');
        }

        return ReleaseFields::list($this->sheet()['fields'] ?? [], 'sheet.fields');
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws InvalidReleaseContent when the stored sheet is not an object
     */
    private function sheet(): array
    {
        $sheet = $this->document['sheet'] ?? [];
        if (!\is_array($sheet) || array_is_list($sheet) && [] !== $sheet) {
            throw InvalidReleaseContent::at('sheet', 'must be an object');
        }

        return $sheet;
    }

    /**
     * @param array<array-key, mixed> $sheet
     *
     * @throws InvalidReleaseContent when the stored sheet is not an object
     */
    private function replaceSheet(array $sheet, \DateTimeImmutable $now): void
    {
        $document = $this->document;
        $document['sheet'] = $sheet;
        ReleaseContent::checkItemRules($document);
        $this->commit($document, $now);
    }

    /**
     * @param array<array-key, mixed> $sheet
     *
     * @return list<string> the names of the declared groups; none without a layout
     *
     * @throws InvalidReleaseContent when "groups" is not a list
     */
    private function declaredGroupNames(array $sheet): array
    {
        $names = [];
        foreach (ReleaseFields::list($sheet['groups'] ?? [], 'sheet.groups') as $group) {
            if (\is_array($group) && \is_string($group['name'] ?? null)) {
                $names[] = $group['name'];
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $group    a group of a layout save
     * @param list<string>            $declared the names of the declared groups
     *
     * @return string|null the declared group it stands for: its "previousName", else its name when that is declared; null for a new group
     *
     * @throws InvalidReleaseContent when "previousName" is not the name of a declared group
     */
    private function currentGroupOf(array $group, string $name, array $declared, string $path): ?string
    {
        $previous = $group['previousName'] ?? null;
        if (null === $previous) {
            return \in_array($name, $declared, true) ? $name : null;
        }

        if (!\is_string($previous)) {
            throw InvalidReleaseContent::at($path.'.previousName', 'must be the name of a declared group');
        }

        if (!\in_array($previous, $declared, true)) {
            throw InvalidReleaseContent::at($path.'.previousName', \sprintf('no declared group is named "%s"', $previous));
        }

        return $previous;
    }

    /**
     * @param list<mixed> $fields
     *
     * @throws InvalidReleaseContent when the new document breaks the item rules
     */
    private function replaceSheetFields(array $fields, \DateTimeImmutable $now): void
    {
        $this->replaceSheet([...$this->sheet(), 'fields' => $fields], $now);
    }

    /**
     * @throws SheetFieldInUse when the draft names this field outside the field itself
     */
    private function refuseWhenSheetFieldReferenced(string $key): void
    {
        $paths = DraftReferences::sheetFieldPathsTo($this->document, $key, $key);
        if ([] !== $paths) {
            throw SheetFieldInUse::at(\sprintf('The sheet field "%s"', $key), $paths);
        }
    }

    /**
     * @return list<mixed> the stored checks; none when the draft has none
     *
     * @throws InvalidReleaseContent when the draft is schema version 1 or "checks" is not a list
     */
    private function checks(): array
    {
        if (1 === $this->schemaVersion()) {
            throw InvalidReleaseContent::at('schemaVersion', 'checks need schema version 2, this draft is version 1');
        }

        return ReleaseFields::list($this->document['checks'] ?? [], 'checks');
    }

    /**
     * @param list<mixed> $checks
     *
     * @throws InvalidReleaseContent when the new document breaks the item rules
     */
    private function replaceChecks(array $checks, \DateTimeImmutable $now): void
    {
        $document = $this->document;
        $document['checks'] = $checks;
        ReleaseContent::checkItemRules($document);
        $this->commit($document, $now);
    }

    /**
     * @throws CheckInUse when a check step names this check
     */
    private function refuseWhenCheckReferenced(string $key): void
    {
        $paths = DraftReferences::checkPathsTo($this->document, $key);
        if ([] !== $paths) {
            throw CheckInUse::at(\sprintf('The check "%s"', $key), $paths);
        }
    }

    /**
     * A band is identified by its key, so a band rename is a drop plus an add: the outcomes that
     * name a dropped band are listed, and nothing cascades.
     *
     * @param array<array-key, mixed> $new
     *
     * @throws CheckInUse when a band of the stored check is missing from the new one and an outcome names it
     */
    private function refuseWhenBandsAreReferenced(string $check, mixed $stored, array $new): void
    {
        $kept = $this->bandKeys($new);
        $paths = [];
        foreach ($this->bandKeys(\is_array($stored) ? $stored : []) as $band) {
            if (!\in_array($band, $kept, true)) {
                $paths = [...$paths, ...DraftReferences::bandPathsTo($this->document, $check, $band)];
            }
        }

        if ([] !== $paths) {
            throw CheckInUse::at(\sprintf('A band of the check "%s"', $check), $paths);
        }
    }

    /**
     * @param array<array-key, mixed> $check
     *
     * @return list<string> the keys of the bands that are objects with a string key
     */
    private function bandKeys(array $check): array
    {
        $bands = $check['bands'] ?? null;
        $keys = [];
        foreach (\is_array($bands) && array_is_list($bands) ? $bands : [] as $band) {
            if (\is_array($band) && \is_string($band['key'] ?? null)) {
                $keys[] = $band['key'];
            }
        }

        return $keys;
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
        $this->commit($document, $now);
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
        $this->commit($document, $now);
    }

    /**
     * Every command ends here: the document and the time change together, and the imports whose item
     * is no longer in the document are dropped, so deleting or renaming an imported item forgets
     * its import. $imports replaces the stored ones (the import commands); null keeps them.
     *
     * @param array<string, mixed>     $document
     * @param list<LibraryImport>|null $imports
     */
    private function commit(array $document, \DateTimeImmutable $now, ?array $imports = null): void
    {
        $items = LibraryItems::of($document);
        $stored = $imports ?? $this->libraryImports();
        $kept = array_values(array_filter($stored, static fn (LibraryImport $import): bool => $items->has($import->ref())));

        $this->document = $document;
        $this->updatedAt = $now;
        if (null !== $imports || \count($kept) !== \count($stored)) {
            $this->libraryImports = array_map(static fn (LibraryImport $import): array => $import->toArray(), $kept);
        }
    }

    /**
     * The plan of an import, with the document it would produce (the items copied, not yet judged).
     *
     * @param list<LibraryItemRef> $refs
     *
     * @return array{LibraryImportPlan, array<string, mixed>}
     */
    private function resolveLibraryImport(self $pack, array $refs): array
    {
        $this->assertLibraryImportable($pack, 'import from the library');
        if ([] === $refs) {
            throw InvalidReleaseContent::at('items', 'must be a non-empty list');
        }

        $source = LibraryItems::of($pack->document);
        $own = LibraryItems::of($this->document);
        $requested = [];
        $conflicts = [];
        foreach ($refs as $ref) {
            if (!$source->has($ref)) {
                throw LibraryItemNotFound::inPack($ref, $pack->key);
            }

            if ($own->has($ref)) {
                $conflicts[$ref->id()] = $ref;
            } else {
                $requested[$ref->id()] = $ref;
            }
        }

        [$imports, $overrides] = $source->closure(array_values($requested), $own);
        $overrides = array_values(array_filter($overrides, static fn (LibraryItemRef $ref): bool => !isset($conflicts[$ref->id()])));

        return [new LibraryImportPlan($imports, $overrides, array_values($conflicts)), $this->withCopies($this->document, $source, $imports)];
    }

    /**
     * @throws LibraryPackRefuses        when this draft is a library pack
     * @throws \InvalidArgumentException when $pack is not a library pack
     * @throws InvalidReleaseContent     when this draft is schema version 1
     */
    private function assertLibraryImportable(self $pack, string $operation): void
    {
        $this->refuseOnPack($operation);
        if (!$pack->isLibraryPack()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a library pack, so nothing can be imported from it.', $pack->key));
        }

        if (1 === $this->schemaVersion()) {
            throw InvalidReleaseContent::at('schemaVersion', 'library imports need schema version 2, this draft is version 1');
        }
    }

    private function libraryImport(LibraryItemRef $ref): ?LibraryImport
    {
        foreach ($this->libraryImports() as $import) {
            if ($import->ref()->is($ref)) {
                return $import;
            }
        }

        return null;
    }

    /**
     * @param list<LibraryItemRef> $refs items of the pack
     *
     * @return list<LibraryImport> one for each item, with the hash of the pack item
     */
    private function importsOf(self $pack, array $refs): array
    {
        $source = LibraryItems::of($pack->document);
        $imports = [];
        foreach ($refs as $ref) {
            $item = $source->find($ref)['item'] ?? [];
            $imports[] = new LibraryImport($ref->section, $ref->key, $pack->key, ReleaseContent::itemHash($item));
        }

        return $imports;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<LibraryItemRef> $refs     items of the pack, appended to the lists they live in
     *
     * @return array<string, mixed>
     *
     * @throws InvalidReleaseContent when a list of the document is not a list
     */
    private function withCopies(array $document, LibraryItems $source, array $refs): array
    {
        foreach ($refs as $ref) {
            $located = $source->find($ref);
            if (null !== $located) {
                $document = LibraryItems::appended($document, $located['list'], $located['item']);
            }
        }

        return $document;
    }

    /**
     * @throws InvalidGameSystemMetadata when the key, owner, name or description is invalid
     */
    private static function blank(GameSystemKind $kind, GameSystemId $id, string $key, string $name, ?string $description, string $ownerId, \DateTimeImmutable $now): self
    {
        $key = self::validKey($key);
        $gameSystem = ['key' => $key, 'name' => GameSystemMetadata::name($name)];
        $description = GameSystemMetadata::description($description);
        if (null !== $description) {
            $gameSystem['description'] = $description;
        }

        return new self($id->toString(), $key, self::validOwner($ownerId), $kind, [
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
     * @throws LibraryPackRefuses when the draft is a library pack
     */
    private function refuseOnPack(string $operation): void
    {
        if (GameSystemKind::LibraryPack === $this->kind) {
            throw LibraryPackRefuses::to($operation, $this->key);
        }
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
