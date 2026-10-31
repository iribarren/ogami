<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tag;

/**
 * The rules of a release upgrade (ADR 0035), pure: how stored values fit the target release, and
 * the plan that lists the result. The Campaign uses the same fitting methods to apply the plan, so
 * what is previewed is what is applied. Validation is CharacterValues' own.
 */
final readonly class ReleaseUpgradePlanner
{
    public function __construct(
        private GameSystemSnapshot $pinned,
        private GameSystemSnapshot $target,
    ) {
    }

    /**
     * @param list<Character>    $characters    in campaign order
     * @param list<Npc>          $npcs          in creation order
     * @param list<Thread>       $threads       in creation order
     * @param list<Place>        $places        in creation order
     * @param array<string, int> $trackerValues
     * @param list<Fact>         $facts         in creation order
     */
    public function plan(array $characters, array $npcs, array $threads, array $places, array $trackerValues, array $facts, ?string $flowKey, bool $hasFlowRun): ReleaseUpgradePlan
    {
        $upgrades = [];
        foreach ($characters as $character) {
            $changes = $this->fit($character)->changes;
            $dropped = $this->droppedTags($character->tags(), ItemKind::Character);
            if ([] !== $changes || [] !== $dropped) {
                $upgrades[] = new CharacterUpgrade($character->id()->toString(), $character->name(), $changes, $dropped);
            }
        }

        return new ReleaseUpgradePlan(
            PinnedRelease::of($this->pinned->gameSystemKey(), $this->pinned->releaseVersion(), $this->pinned->name()),
            PinnedRelease::of($this->target->gameSystemKey(), $this->target->releaseVersion(), $this->target->name()),
            $upgrades,
            $this->droppedFrom($npcs, ItemKind::Npc),
            $this->droppedFrom($threads, ItemKind::Thread),
            $this->droppedFrom($places, ItemKind::Place),
            $this->trackerChanges($trackerValues),
            $this->unslotted($facts, $npcs, $threads, $places),
            $this->flowChange($flowKey, $hasFlowRun),
        );
    }

    /**
     * The Character's values on the target sheet: a kept value stays, the rest is changed as the
     * decision table says. Derived fields hold no value and never appear. An added field never
     * rolls, so the plan stays deterministic (ADR 0040): a number field takes its default, a track
     * with a start max starts full at that formula over the fitted values.
     */
    public function fit(Character $character): FittedValues
    {
        $stored = $character->values();
        $newSheet = $this->target->sheet();
        $values = [];
        $changes = [];
        $starts = [];
        foreach ($newSheet->fields() as $field) {
            if ($field instanceof DerivedField) {
                if (\array_key_exists($field->key, $stored)) {
                    $changes[] = new FieldChange($field->key, $field->label, FieldChangeKind::Removed, $stored[$field->key], null);
                }

                continue;
            }

            if (!\array_key_exists($field->key, $stored)) {
                $values[$field->key] = CharacterValues::initial($field);
                if ($field instanceof TrackField && $field->startMax instanceof Formula) {
                    $starts[$field->key] = [$field, \count($changes)];
                }

                $changes[] = new FieldChange($field->key, $field->label, FieldChangeKind::Added, null, $values[$field->key]);

                continue;
            }

            [$values[$field->key], $kind] = $this->fitValue($field, $stored[$field->key]);
            if (null !== $kind) {
                $changes[] = new FieldChange($field->key, $field->label, $kind, $stored[$field->key], $values[$field->key]);
            }
        }

        if ([] !== $starts) {
            // Every start max reads number and derived fields only, so all see the fitted values.
            $reader = SheetValues::of($newSheet, $values, []);
            foreach ($starts as $key => [$field, $index]) {
                $max = $this->startMax($field, $reader);
                $values[$key] = ['current' => $max, 'max' => $max];
                $changes[$index] = new FieldChange($key, $field->label, FieldChangeKind::Added, null, $values[$key]);
            }
        }

        foreach ($this->removedKeys($stored) as $key) {
            $changes[] = new FieldChange($key, $this->pinned->sheet()->field($key)->label ?? $key, FieldChangeKind::Removed, $stored[$key], null);
        }

        return new FittedValues($values, array_values($changes));
    }

    /**
     * @param list<string> $tags release Tag keys
     *
     * @return list<string> those the target release still has and still applies to this kind, as stored
     */
    public function keptTags(array $tags, ItemKind $kind): array
    {
        return array_values(array_filter($tags, fn (string $key): bool => ($tag = $this->target->tag($key)) instanceof Tag && $tag->isFor($kind)));
    }

    /**
     * @param array<string, int> $trackerValues
     *
     * @return array<string, int> the values the target release's Trackers keep, by key
     */
    public function keptTrackerValues(array $trackerValues): array
    {
        $kept = [];
        foreach ($trackerValues as $key => $value) {
            $tracker = $this->target->tracker($key);
            if ($tracker instanceof \App\Play\Domain\GameSystem\Tracker) {
                $kept[$key] = $tracker->clamp($value);
            }
        }

        return $kept;
    }

    /**
     * The free fact a slot fact becomes when its slot is gone: its text (the subject's name for a
     * subject fact, as it has no text) and its label (the old slot's name when it had none), with its
     * links, plus the subject as a link when it fits.
     *
     * @param list<Npc>    $npcs
     * @param list<Thread> $threads
     * @param list<Place>  $places
     */
    public function freed(Fact $fact, array $npcs, array $threads, array $places): Fact
    {
        $subject = $fact->subject();
        $links = $fact->links();
        if ($subject instanceof \App\Play\Domain\Campaign\Facts\FactLink) {
            $already = array_filter($links, static fn (\App\Play\Domain\Campaign\Facts\FactLink $link): bool => $link->equals($subject));
            if ([] === $already && \count($links) < Fact::MAX_LINKS) {
                $links[] = $subject;
            }
        }

        return Fact::of($fact->id(), null, $this->freedLabel($fact), $this->freedText($fact, $npcs, $threads, $places), null, $links);
    }

    /**
     * Same Flow when the target has it, else its default Flow, else its first; free play stays free
     * and a target without Flows makes it free play.
     */
    public function targetFlowKey(?string $flowKey): ?string
    {
        if (null === $flowKey) {
            return null;
        }

        return $this->target->flow($flowKey)->key ?? $this->target->defaultFlow()->key ?? array_first($this->target->flows())->key ?? null;
    }

    /**
     * The start max of a track added by the upgrade, inside the track limits; 0 when its formula
     * cannot be computed, as a track added without one starts.
     */
    private function startMax(TrackField $field, SheetValues $reader): int
    {
        try {
            return max(0, min(CharacterValues::MAX_TRACK, $field->startMax?->evaluate($reader) ?? 0));
        } catch (FormulaEvaluationFailed) {
            return 0;
        }
    }

    /**
     * @return array{int|string|list<string>|list<array<string, int|string>>|array{current: int, max: int}, ?FieldChangeKind} the new value, and how it changed (null when kept)
     */
    private function fitValue(Field $field, mixed $value): array
    {
        $old = $this->pinned->sheet()->field($field->key);
        if ($old instanceof Field && $old::class !== $field::class) {
            $candidate = $this->converted($old, $field, $value);
            if (null === $candidate) {
                return [CharacterValues::initial($field), FieldChangeKind::Reset];
            }

            $kind = FieldChangeKind::Converted;
        } else {
            [$candidate, $kind] = $this->adjusted($field, $value);
        }

        try {
            $valid = CharacterValues::resolve($this->target->sheet(), [$field->key => $candidate])[$field->key];
        } catch (InvalidCharacter) {
            return [CharacterValues::initial($field), FieldChangeKind::Reset];
        }

        if ($this->normalized($field, $valid) === $this->normalized($field, $value)) {
            return [$valid, null];
        }

        return [$valid, $kind ?? FieldChangeKind::Reset];
    }

    /**
     * A track's keys sorted, so the same {current, max} stored in another key order is the same value;
     * a list keeps its order, except the marked keys of a conditions field, which are a set.
     */
    private function normalized(Field $field, mixed $value): mixed
    {
        if (\is_array($value) && !array_is_list($value)) {
            ksort($value);
        }

        if ($field instanceof ConditionsField && \is_array($value)) {
            sort($value);
        }

        return $value;
    }

    /**
     * Moves a number or track inside its bounds, cuts a list to its limit and a conditions field to the
     * options it still declares, in their new order; anything else, or a value of the wrong shape,
     * comes back unchanged for the validation to judge.
     *
     * @return array{mixed, ?FieldChangeKind}
     */
    private function adjusted(Field $field, mixed $value): array
    {
        if ($field instanceof NumberField && \is_int($value)) {
            return [max($field->min ?? -NumberField::LIMIT, min($field->max ?? NumberField::LIMIT, $value)), FieldChangeKind::Clamped];
        }

        if ($field instanceof TrackField && \is_array($value) && \is_int($value['current'] ?? null) && \is_int($max = $field->max ?? $value['max'] ?? null)) {
            $max = max(0, min(CharacterValues::MAX_TRACK, $max));

            return [['current' => max(0, min($max, $value['current'])), 'max' => $max], FieldChangeKind::Clamped];
        }

        if ($field instanceof ListField && \is_array($value) && array_is_list($value) && \count($value) > $field->limit()) {
            return [\array_slice($value, 0, $field->limit()), FieldChangeKind::Trimmed];
        }

        if ($field instanceof InventoryField && \is_array($value) && array_is_list($value)) {
            return $this->adjustedInventory($field, $value);
        }

        if ($field instanceof ConditionsField && \is_array($value) && array_is_list($value)) {
            $kept = $field->ordered(array_values(array_filter($value, \is_string(...))));

            return [$kept, \count($kept) < \count($value) ? FieldChangeKind::Trimmed : null];
        }

        return [$value, null];
    }

    /**
     * What a `list` and an `inventory` become when the field keeps its key and changes between them
     * (ADR 0045), before the target field's own limits judge it; null for any other type change, or a
     * value of the wrong shape, which reset.
     *
     * @return ?list<mixed>
     */
    private function converted(Field $old, Field $field, mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        if ($old instanceof ListField && $field instanceof InventoryField) {
            return $this->fitStacks($field, $this->stacksFromList($field, $value));
        }

        if ($old instanceof InventoryField && $field instanceof ListField) {
            return \array_slice(array_map($this->stackName(...), array_values(array_filter($value, \is_array(...)))), 0, $field->limit());
        }

        return null;
    }

    /**
     * Each string naming a catalog entry the inventory accepts (trimmed, ignoring case) is a stack of
     * it, duplicates adding up in the first one's place; any other non-blank string is a free stack.
     *
     * @param list<mixed> $items
     *
     * @return list<array<string, int|string>>
     */
    private function stacksFromList(InventoryField $field, array $items): array
    {
        $byName = [];
        foreach ($field->gear() as $entry) {
            $byName[mb_strtolower(trim($entry->name))] = $entry->key;
        }

        $stacks = [];
        $position = [];
        foreach ($items as $item) {
            $name = \is_string($item) ? trim($item) : '';
            if ('' === $name) {
                continue;
            }

            $gear = $byName[mb_strtolower($name)] ?? null;
            if (null === $gear) {
                $stacks[] = ['name' => mb_substr($name, 0, CharacterValues::MAX_ITEM_LENGTH), 'quantity' => 1, 'slots' => 0];
            } elseif (isset($position[$gear])) {
                $stacks[$position[$gear]]['quantity'] = min(CharacterValues::MAX_QUANTITY, (int) $stacks[$position[$gear]]['quantity'] + 1);
            } else {
                $position[$gear] = \count($stacks);
                $stacks[] = ['gear' => $gear, 'quantity' => 1];
            }
        }

        return $stacks;
    }

    /**
     * The inventory of the same type: a stack whose gear the target field does not accept becomes a
     * free stack with the name and slots the source catalog gave it, then the stacks are fitted. A
     * converted stack makes the change `converted`, even when the fit also dropped some (the plan
     * shows the before and after values, so nothing is hidden).
     *
     * @param list<mixed> $value
     *
     * @return array{mixed, ?FieldChangeKind}
     */
    private function adjustedInventory(InventoryField $field, array $value): array
    {
        $stacks = [];
        $converted = false;
        foreach ($value as $stack) {
            if (!\is_array($stack)) {
                return [$value, null];
            }

            if (\is_string($stack['gear'] ?? null) && !$field->entry($stack['gear']) instanceof GearEntry) {
                $source = $this->pinned->gearEntry($stack['gear']);
                $stack = ['name' => mb_substr($source->name ?? $stack['gear'], 0, CharacterValues::MAX_ITEM_LENGTH), 'quantity' => $stack['quantity'] ?? 1, 'slots' => $source->slots ?? GearEntry::DEFAULT_SLOTS];
                $converted = true;
            }

            $stacks[] = $stack;
        }

        $fitted = $this->fitStacks($field, $stacks);

        return [$fitted, $converted ? FieldChangeKind::Converted : (\count($fitted) < \count($stacks) ? FieldChangeKind::Trimmed : null)];
    }

    /**
     * The stacks that fit: the first `maxStacks`, then the longest start whose slots, quantity times
     * slots, stay within the capacity; the rest is dropped from the end.
     *
     * @param list<array<mixed>> $stacks
     *
     * @return list<array<mixed>>
     */
    private function fitStacks(InventoryField $field, array $stacks): array
    {
        $fitted = [];
        $used = 0;
        foreach (\array_slice($stacks, 0, $field->maxStacks) as $stack) {
            $slots = \is_string($stack['gear'] ?? null) ? $field->entry($stack['gear'])->slots ?? 0 : $this->integer($stack['slots'] ?? null, 0);
            $used += $this->integer($stack['quantity'] ?? null, 0) * $slots;
            if (null !== $field->capacity && $used > $field->capacity) {
                break;
            }

            $fitted[] = $stack;
        }

        return $fitted;
    }

    private function integer(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }

    /**
     * The list item a stack becomes: its name (the source catalog's for gear), with ` ×n` above one.
     *
     * @param array<mixed> $stack
     */
    private function stackName(array $stack): string
    {
        $name = \is_string($stack['gear'] ?? null) ? ($this->pinned->gearEntry($stack['gear'])->name ?? $stack['gear']) : (\is_string($stack['name'] ?? null) ? $stack['name'] : '');
        $quantity = $this->integer($stack['quantity'] ?? null, 1);

        return mb_substr($quantity > 1 ? \sprintf('%s ×%d', $name, $quantity) : $name, 0, CharacterValues::MAX_ITEM_LENGTH);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return list<string> stored keys the target sheet holds no value for: the old sheet's fields in
     *                      its order, then any other stored key sorted
     */
    private function removedKeys(array $stored): array
    {
        $kept = [];
        foreach ($this->target->sheet()->fields() as $field) {
            $kept[$field->key] = true;
        }

        $oldOrder = array_map(static fn (Field $field): string => $field->key, $this->pinned->sheet()->fields());
        $extra = array_diff(array_keys($stored), $oldOrder);
        sort($extra);

        return array_values(array_filter([...$oldOrder, ...$extra], static fn (string $key): bool => \array_key_exists($key, $stored) && !isset($kept[$key])));
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function droppedTags(array $tags, ItemKind $kind): array
    {
        return array_values(array_diff($tags, $this->keptTags($tags, $kind)));
    }

    /**
     * @param list<Npc|Thread|Place> $items
     *
     * @return list<DroppedTags>
     */
    private function droppedFrom(array $items, ItemKind $kind): array
    {
        $dropped = [];
        foreach ($items as $item) {
            $tags = $this->droppedTags($item->tags(), $kind);
            if ([] !== $tags) {
                $dropped[] = new DroppedTags($item->id()->toString(), $item->name(), $tags);
            }
        }

        return $dropped;
    }

    /**
     * @param array<string, int> $trackerValues
     *
     * @return list<TrackerChange> by Tracker key
     */
    private function trackerChanges(array $trackerValues): array
    {
        ksort($trackerValues);
        $changes = [];
        foreach ($trackerValues as $key => $value) {
            $key = (string) $key;
            $tracker = $this->target->tracker($key);
            if (!$tracker instanceof \App\Play\Domain\GameSystem\Tracker) {
                $changes[] = new TrackerChange($key, $this->pinned->tracker($key)->name ?? $key, TrackerChangeKind::Removed, $value, null);
            } elseif ($tracker->clamp($value) !== $value) {
                $changes[] = new TrackerChange($key, $tracker->name, TrackerChangeKind::Clamped, $value, $tracker->clamp($value));
            }
        }

        return $changes;
    }

    /**
     * @param list<Fact>   $facts
     * @param list<Npc>    $npcs
     * @param list<Thread> $threads
     * @param list<Place>  $places
     *
     * @return list<UnslottedFact>
     */
    private function unslotted(array $facts, array $npcs, array $threads, array $places): array
    {
        $unslotted = [];
        foreach ($facts as $fact) {
            if (null !== $fact->slot() && !$this->target->factSlot($fact->slot()) instanceof FactSlot) {
                $unslotted[] = new UnslottedFact($fact->id()->toString(), $fact->slot(), $this->freedLabel($fact), $this->freedText($fact, $npcs, $threads, $places));
            }
        }

        return $unslotted;
    }

    private function flowChange(?string $flowKey, bool $hasFlowRun): FlowChange
    {
        $to = $this->targetFlowKey($flowKey);

        return new FlowChange($flowKey, $to, $hasFlowRun || null !== $to);
    }

    private function freedLabel(Fact $fact): string
    {
        $label = $fact->label() ?? $this->pinned->factSlot((string) $fact->slot())->label ?? (string) $fact->slot();

        return mb_substr($label, 0, Fact::MAX_LABEL_LENGTH);
    }

    /**
     * @param list<Npc>    $npcs
     * @param list<Thread> $threads
     * @param list<Place>  $places
     */
    private function freedText(Fact $fact, array $npcs, array $threads, array $places): string
    {
        $subject = $fact->subject();
        if (!$subject instanceof \App\Play\Domain\Campaign\Facts\FactLink || null !== $fact->text()) {
            return (string) $fact->text();
        }

        $items = match ($subject->kind) {
            FactLinkKind::Npc => $npcs,
            FactLinkKind::Thread => $threads,
            FactLinkKind::Place => $places,
            FactLinkKind::Fact => [],
        };
        $named = array_find($items, static fn (Npc|Thread|Place $item): bool => $item->id()->toString() === $subject->id);

        return $named?->name() ?? $subject->id;
    }
}
