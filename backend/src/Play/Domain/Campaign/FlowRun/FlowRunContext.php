<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\InventoryFull;
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
use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Tracker;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * What a FlowRun command works with, handed over by its Campaign: the pinned release, the Flow
 * played, the command's time, the campaign's sessions and scenes, its Tracker values and its
 * Characters' sheets, which Effects and shops change, and the random source sheet Effects roll their dice
 * and shops draw their stock with.
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
        return $this->effects()->trackerValue($key);
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
        return $this->effects()->trackerValues();
    }

    /**
     * Applies a tracker Effect: adds or sets a literal or a formula's value, clamped
     * to the Tracker's range. It is no hand edit, so no FlowRun history records it.
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero or overflows
     */
    public function applyTrackerEffect(TrackerEffect $effect): void
    {
        ['from' => $from, 'to' => $to] = $this->effects()->applyTrackerEffect($effect);
        if ($to !== $from) {
            $tracker = $this->tracker($effect->tracker);
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
     * Applies a sheet Effect to a Character (ADR 0027, 0041; see CampaignEffects): the Flow never
     * asks for a pick, so a mark without an option cannot reach it. A voluntary effect (a choice
     * option's, a prompt's or a character step's) is refused when it would take the currency under its floor.
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero, overflows or reads a value the sheet lacks
     * @throws InsufficientFunds when a voluntary effect would take the currency under its floor
     */
    public function applySheetEffect(SheetEffect $effect, string $characterId, bool $voluntary = false): SheetChange
    {
        if (SheetOperation::Mark === $effect->op && null === $effect->value) {
            throw new \LogicException(\sprintf('Marking conditions field "%s" needs an option.', $effect->field));
        }

        return $this->effects()->applySheetEffect($effect, $characterId, refuseBelowFloor: $voluntary);
    }

    /**
     * Draws the stock of a shop step with the command's random source (ADR 0045).
     */
    public function drawStock(ShopStep $step): ShopStock
    {
        return ShopStock::draw($step, $this->random ?? throw new \LogicException(\sprintf('Shop step "%s" draws its stock without a random source.', $step->key)));
    }

    /**
     * Makes a shop transaction for the Character the result names (ADR 0045; see CampaignEffects).
     *
     * @param list<GearEntry> $onSale the entries the shop has on sale now
     *
     * @throws InvalidStepResult when the Character is unknown or a line cannot be traded
     * @throws InsufficientFunds when the currency would end under its floor
     * @throws InventoryFull     when a purchase takes an inventory field over its slots or stacks
     */
    public function trade(ShopStep $step, array $onSale, StepResult $result): ShopTrade
    {
        $id = $result->characterId ?? '';
        if (null === $this->characterName($id)) {
            throw InvalidStepResult::unknownSheetCharacter($step->key, StepKind::Shop, $id);
        }

        return $this->effects()->applyShopTrade($step, $onSale, $id, $result->buy, $result->sell);
    }

    /**
     * A literal as it is, a formula over the campaign's current Tracker values.
     *
     * @throws FlowFormulaFailed when the formula divides by zero or overflows
     */
    public function evaluate(int|Formula $value): int
    {
        return $this->effects()->evaluate($value);
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

    private function effects(): CampaignEffects
    {
        return new CampaignEffects($this->campaign, $this->release, $this->random);
    }

    private function tracker(string $key): Tracker
    {
        return $this->release->tracker($key) ?? throw new \LogicException(\sprintf('The pinned release has no Tracker "%s".', $key));
    }
}
