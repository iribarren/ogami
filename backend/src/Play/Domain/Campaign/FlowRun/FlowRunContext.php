<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\ItemCount;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tracker;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * What a FlowRun command works with, handed over by its Campaign: the pinned release, the Flow
 * played, the command's time, the campaign's sessions and scenes, its Tracker values and its
 * Characters' sheets, which Effects change, and the random source sheet Effects roll their dice with.
 * It also takes the notes of what the Flow decided on its own (see FlowNote).
 */
final readonly class FlowRunContext
{
    public function __construct(
        private Campaign $campaign,
        public GameSystemSnapshot $release,
        public Flow $flow,
        public \DateTimeImmutable $at,
        private ?RandomNumberGenerator $random = null,
    ) {
    }

    /**
     * The number of the session under way; null when none is.
     */
    public function sessionNumber(): ?int
    {
        return $this->campaign->currentSession()?->number();
    }

    /**
     * The number of the current scene in the session under way; null when there is none.
     */
    public function sceneNumber(): ?int
    {
        return $this->campaign->currentScene()?->number();
    }

    /**
     * Starts a scene of play of this Scene Type with its default title.
     *
     * @return int its number in the session under way
     */
    public function startScene(SceneType $sceneType): int
    {
        return $this->campaign->startGuidedScene($sceneType, $this->at)->number();
    }

    /**
     * Starts a hook Scene of this hook of the phase with its default title.
     *
     * @return int its number in the session under way
     */
    public function startHookScene(Hook $hook, Phase $phase): int
    {
        return $this->campaign->startHookScene($hook, $hook->defaultTitle((int) $this->sessionNumber(), $phase), $this->at)->number();
    }

    /**
     * Ends the session under way (its session closing finished).
     *
     * @throws NoCurrentSession
     */
    public function endSession(): void
    {
        $this->campaign->endSession($this->at);
    }

    public function sceneType(string $key): SceneType
    {
        return $this->release->sceneType($key) ?? throw new \LogicException(\sprintf('The pinned release has no Scene Type "%s".', $key));
    }

    /**
     * The campaign's current value of a Tracker of the pinned release.
     */
    public function trackerValue(string $key): int
    {
        return $this->campaign->trackerValue($this->tracker($key));
    }

    /**
     * The number of open NPCs or Threads, or of Characters, a count condition counts.
     */
    public function openItemCount(ItemCount $count): int
    {
        return $this->campaign->openItemCount($count->of, $count->tags);
    }

    /**
     * The candidates of a pick step: the campaign's open items (every Character) of its kind carrying all its Tags.
     */
    public function pickCandidates(PickStep $step): PickCandidates
    {
        return $this->campaign->pickCandidates($step->of, $step->tags);
    }

    /**
     * The current names of the picked items, in pick order; an item no longer in the campaign
     * has none.
     *
     * @return list<string>
     */
    public function pickedNames(?PickedItems $picked): array
    {
        return $picked instanceof PickedItems ? $this->campaign->itemNames($picked->kind, $picked->ids) : [];
    }

    /**
     * The current name of a Character of the campaign; null when it holds none with this id.
     */
    public function characterName(string $id): ?string
    {
        return $this->campaign->itemNames(ItemKind::Character, [$id])[0] ?? null;
    }

    /**
     * The ids of the Characters in the current Scene.
     *
     * @return list<string>
     */
    public function sceneCharacterIds(): array
    {
        return array_map(static fn (CharacterId $id): string => $id->toString(), $this->campaign->currentScene()?->characters() ?? []);
    }

    /**
     * The ids of the Characters in the session under way's party.
     *
     * @return list<string>
     */
    public function partyCharacterIds(): array
    {
        return array_map(static fn (CharacterId $id): string => $id->toString(), $this->campaign->currentSession()?->party() ?? []);
    }

    /**
     * Adds the NPCs or Characters among the items to the current scene's cast; Threads have no
     * cast. Characters also join the current session's party when $toParty (in a hook).
     *
     * @throws NoCurrentScene
     */
    public function joinSceneCast(PickedItems $items, bool $toParty = false): void
    {
        match ($items->kind) {
            ItemKind::Npc => $this->campaign->addToCurrentSceneCast(array_map(NpcId::fromString(...), $items->ids)),
            ItemKind::Character => $this->campaign->addToCurrentSceneCharacters(array_map(CharacterId::fromString(...), $items->ids), $toParty),
            ItemKind::Thread => null,
        };
    }

    /**
     * Creates an open NPC (a createNpc Effect, its name and agenda already rendered).
     *
     * @param list<string> $tags release Tag keys
     *
     * @return ?PickedItems the new NPC; null when the campaign holds the most NPCs it can
     */
    public function createNpc(string $name, ?string $agenda, array $tags): ?PickedItems
    {
        $npc = $this->campaign->createNpcByEffect($name, $agenda, $tags, $this->release);

        return $npc instanceof Npc ? new PickedItems(ItemKind::Npc, [$npc->id()->toString()]) : null;
    }

    /**
     * Creates an open Thread (a createThread Effect, its name already rendered).
     *
     * @param list<string> $tags release Tag keys
     *
     * @return ?PickedItems the new Thread; null when the campaign holds the most Threads it can
     */
    public function createThread(string $name, array $tags): ?PickedItems
    {
        $thread = $this->campaign->createThreadByEffect($name, $tags, $this->release);

        return $thread instanceof Thread ? new PickedItems(ItemKind::Thread, [$thread->id()->toString()]) : null;
    }

    /**
     * Fills a Fact slot (a fillFact Effect, its text already rendered, or the item picked).
     *
     * @return ?Fact the slot fact; null when a new one would pass the campaign's 500 facts
     */
    public function fillFact(string $slot, ?string $text, ?FactLink $subject): ?Fact
    {
        return $this->campaign->fillFactByEffect($slot, $text, $subject, $this->release);
    }

    /**
     * @return array<string, string> the current value of every Fact slot of the pinned release, by key ('' while unfilled)
     */
    public function factValues(): array
    {
        return $this->campaign->factValues($this->release);
    }

    /**
     * Closes the open Threads among the picked items (a closeThread Effect); nothing happens for
     * NPCs or nothing picked.
     */
    public function closePickedThreads(?PickedItems $picked): void
    {
        if ($picked instanceof PickedItems && ItemKind::Thread === $picked->kind) {
            $this->campaign->closeThreadsById($picked->ids);
        }
    }

    /**
     * @return array<string, int> the campaign's current value of every Tracker of the pinned release, by key
     */
    public function trackerValues(): array
    {
        return $this->campaign->trackerValuesOf($this->release);
    }

    /**
     * Applies a tracker Effect: adds or sets a literal or a formula's value, clamped
     * to the Tracker's range. It is no hand edit, so no FlowRun history records it.
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero or overflows
     */
    public function applyTrackerEffect(TrackerEffect $effect): void
    {
        $tracker = $this->tracker($effect->tracker);
        $from = $this->campaign->trackerValue($tracker);
        $to = $this->campaign->applyTrackerChange($tracker, $effect->op, $this->evaluate($effect->value));
        if ($to !== $from) {
            $this->note(FlowNote::tracker($tracker->key, $tracker->name, $from, $to));
        }
    }

    /**
     * Notes what the Flow decided on its own, for the journal to tell afterwards.
     */
    public function note(FlowNote $note): void
    {
        $this->campaign->noteFlow($note);
    }

    /**
     * Applies a sheet Effect to a Character (ADR 0027): adds, subtracts or sets a literal, a formula
     * over the Character's sheet as it is now or a dice roll, clamped to the field (a track's current
     * value to 0..its max, a number to its min and max, else the contract's limit). Mark and clear
     * change a conditions field instead (ADR 0041): the change tells the count marked before and after.
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero, overflows or reads a value the sheet lacks
     */
    public function applySheetEffect(SheetEffect $effect, string $characterId): SheetChange
    {
        $id = CharacterId::fromString($characterId);
        $character = $this->campaign->character($id) ?? throw new \LogicException(\sprintf('The campaign has no Character "%s".', $characterId));
        $field = $this->release->sheet()->field($effect->field);
        if ($field instanceof ConditionsField) {
            return $this->applyConditionEffect($effect, $id, $character, $field);
        }

        if (!$field instanceof NumberField && !$field instanceof TrackField) {
            throw new \LogicException(\sprintf('The pinned release has no number or track field "%s".', $effect->field));
        }

        $dice = null;
        $value = $effect->value;
        if ($value instanceof DiceExpression) {
            $dice = $value->roll($this->random ?? throw new \LogicException('A sheet Effect rolls dice without a random source.'));
            $value = $dice->total();
        } elseif ($value instanceof Formula) {
            try {
                $value = $value->evaluate(SheetValues::of($this->release->sheet(), $character->values(), $this->trackerValues()));
            } catch (FormulaEvaluationFailed $failure) {
                throw FlowFormulaFailed::of($value, $failure);
            }
        }

        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('Field "%s" takes a number, not an option key.', $field->key));
        }

        $stored = $character->values()[$field->key] ?? CharacterValues::initial($field);
        $track = \is_array($stored) && \is_int($stored['current'] ?? null) && \is_int($stored['max'] ?? null) ? ['current' => $stored['current'], 'max' => $stored['max']] : null;
        $before = $track['current'] ?? (\is_int($stored) ? $stored : 0);
        $changed = match ($effect->op) {
            SheetOperation::Add => $before + $value,
            SheetOperation::Subtract => $before - $value,
            SheetOperation::Set => $value,
            SheetOperation::Mark, SheetOperation::Clear => throw new \LogicException(\sprintf('Field "%s" is changed with a number, not marked or cleared.', $field->key)),
        };
        // A sum past the integers is a float; clamping brings it back within the bounds.
        [$min, $max] = $field instanceof TrackField
            ? [0, $track['max'] ?? 0]
            : [$field->min ?? -NumberField::LIMIT, $field->max ?? NumberField::LIMIT];
        $after = max($min, min($max, $changed));
        $this->campaign->changeCharacterValue($id, $field->key, $field instanceof TrackField ? ['current' => $after, 'max' => $max] : $after);

        return new SheetChange($field->key, $before, $after, $dice?->expression()->notation(), $dice?->total());
    }

    /**
     * Marks an option of a conditions field (nothing changes when it is marked already), clears one
     * (nothing changes when it is not marked) or, without an option, clears them all.
     */
    private function applyConditionEffect(SheetEffect $effect, CharacterId $id, Character $character, ConditionsField $field): SheetChange
    {
        $option = $effect->value;
        if (!\is_string($option) && null !== $option) {
            throw new \LogicException(\sprintf('Conditions field "%s" takes an option key, not a number or a formula.', $field->key));
        }

        if (null !== $option && !$field->has($option)) {
            throw new \LogicException(\sprintf('Conditions field "%s" has no option "%s".', $field->key, $option));
        }

        $stored = $character->values()[$field->key] ?? CharacterValues::initial($field);
        $before = \is_array($stored) ? array_values(array_filter($stored, \is_string(...))) : [];
        if (null === $option && SheetOperation::Mark === $effect->op) {
            throw new \LogicException(\sprintf('Marking conditions field "%s" needs an option.', $field->key));
        }

        $after = match ($effect->op) {
            SheetOperation::Mark => $field->ordered([...$before, (string) $option]),
            SheetOperation::Clear => null === $option ? [] : array_values(array_diff($before, [$option])),
            default => throw new \LogicException(\sprintf('Conditions field "%s" is marked and cleared, not changed with "%s".', $field->key, $effect->op->value)),
        };
        $this->campaign->changeCharacterValue($id, $field->key, $after);

        return new SheetChange($field->key, \count($before), \count($after));
    }

    /**
     * A literal as it is, a formula over the campaign's current Tracker values.
     *
     * @throws FlowFormulaFailed when the formula divides by zero or overflows
     */
    public function evaluate(int|Formula $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        try {
            return $value->evaluate(new TrackerValues($this));
        } catch (FormulaEvaluationFailed $failure) {
            throw FlowFormulaFailed::of($value, $failure);
        }
    }

    /**
     * Renames the current scene.
     *
     * @throws NoCurrentScene
     * @throws InvalidSceneTitle
     */
    public function renameScene(string $title): void
    {
        $this->campaign->renameCurrentScene($title);
    }

    /**
     * Switches the current scene's Scene Type in place, without telling the FlowRun (it switches
     * itself).
     *
     * @return ?string the scene's Scene Type key before the switch
     *
     * @throws NoCurrentScene
     */
    public function switchSceneType(SceneType $sceneType): ?string
    {
        $from = $this->campaign->currentScene()?->sceneType();
        $this->campaign->switchSceneType($sceneType);

        return $from;
    }

    private function tracker(string $key): Tracker
    {
        return $this->release->tracker($key) ?? throw new \LogicException(\sprintf('The pinned release has no Tracker "%s".', $key));
    }
}
