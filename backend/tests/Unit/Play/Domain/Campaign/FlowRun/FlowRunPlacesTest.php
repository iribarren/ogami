<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Places in a FlowRun (ADR 0047) on a test release with the Tags "near" (Places only) and "old" (every
 * kind), the fact slots "site" (a place) and "expedition" (a thread) and Scene Types for each part: the
 * createPlace Effect, closeThread by fact slot, a pick and a count of Places, and a place slot filled
 * by the picked or created Place.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(Campaign::class)]
final class FlowRunPlacesTest extends FlowRunTestCase
{
    #[Test]
    public function createPlaceCreatesAnOpenPlaceWithTheEffectsTagsAndItBecomesPickedButJoinsNoCast(): void
    {
        $campaign = $this->inScene('make');
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->snapshot());
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());

        $campaign->completeFlowStep('ask', self::prompt('Saltmarsh'), $this->snapshot(), self::at());

        $place = $this->onlyPlace($campaign);
        self::assertSame(['Camp at Saltmarsh', ['near'], [], null, 1, true], [$place->name(), $place->tags(), $place->freeTags(), $place->notes(), $place->weight(), $place->isOpen()]);
        self::assertEquals(new PickedItems(ItemKind::Place, [$place->id()->toString()]), self::flowRunOf($campaign)->picked());
        self::assertSame('Picked: Camp at Saltmarsh', $campaign->flowRunView($this->snapshot())?->step?->title);
        self::assertSame(['ana'], $this->cast($campaign), 'A Place joins no cast.');
    }

    #[Test]
    public function aMissingNameBecomesNewWithTheFirstTagsLabelOrPlaceAndALongOneIsCut(): void
    {
        foreach ([['anon', 'New Place'], ['tagged', 'New Near']] as [$sceneType, $name]) {
            $campaign = $this->inScene($sceneType);

            $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());

            self::assertSame($name, $this->onlyPlace($campaign)->name());
        }

        $campaign = $this->inScene('long');
        $campaign->completeFlowStep('make', self::prompt(str_repeat('n', 250)), $this->snapshot(), self::at());

        self::assertSame(200, mb_strlen($this->onlyPlace($campaign)->name()));
    }

    #[Test]
    public function whenTheCampaignHoldsTheLimitNothingIsCreatedAndPickedStays(): void
    {
        $campaign = $this->inScene('limit');
        foreach (range(1, Campaign::MAX_PLACES) as $n) {
            $campaign->addPlace(PlaceId::fromString('place-'.$n), 'Place '.$n, null, [], [], 1, $this->snapshot());
        }

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Place, [new PickedItem('place-7', 'Place 7')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertCount(Campaign::MAX_PLACES, $campaign->places());
        self::assertSame('Picked: Place 7', $campaign->flowRunView($this->snapshot())?->step?->title);
    }

    #[Test]
    public function aCreatePlaceWhoseGeneratedIdIsTakenFailsAndChangesNothing(): void
    {
        $campaign = $this->inScene('anon');
        $campaign->addPlace(GeneratedItemId::place($campaign->id()->toString(), 1), 'Squatter', null, [], [], 1, $this->snapshot());
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());
            self::fail('The Effect must not overwrite or duplicate the Place holding its id.');
        } catch (PlaceAlreadyExists) {
        }

        self::assertEquals($before, $campaign);
        self::assertSame(['make', null], [self::flowRunOf($campaign)->stepKey(), self::flowRunOf($campaign)->picked()]);
    }

    #[Test]
    public function closeThreadWithAFactSlotClosesTheThreadFillingItEvenAfterPickedMovedOn(): void
    {
        $campaign = $this->inScene('expedition');
        $campaign->completeFlowStep('open', self::prompt('ok'), $this->snapshot(), self::at());
        $campaign->completeFlowStep('other', self::prompt('ok'), $this->snapshot(), self::at());
        $expedition = $this->factThread($campaign, 'expedition');
        self::assertSame(['Expedition'], array_map(static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): string => $thread->name(), array_filter($campaign->threads(), static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): bool => $thread->id()->equals($expedition))));

        $campaign->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        $sideQuest = array_find($campaign->threads(), static fn ($thread): bool => 'Side quest' === $thread->name());
        self::assertSame([false, true], [$campaign->thread($expedition)?->isOpen(), $sideQuest?->isOpen()]);
    }

    #[Test]
    public function closeThreadWithAFactSlotDoesNothingWhenTheSlotIsEmptyOrItsThreadIsAlreadyClosed(): void
    {
        $empty = $this->inScene('closeslot');
        $empty->addThread(ThreadId::fromString('t1'), 'Thread', [], [], 1, $this->snapshot());
        $empty->addPlace(PlaceId::fromString('p1'), 'Saltmarsh', null, [], [], 1, $this->snapshot());
        $empty->completeFlowStep('lead', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Thread')]), $this->snapshot(), self::at());
        $empty->completeFlowStep('where', StepResult::pick(ItemKind::Place, [new PickedItem('p1', 'Saltmarsh')]), $this->snapshot(), self::at());
        self::assertEquals(new PickedItems(ItemKind::Place, ['p1']), self::flowRunOf($empty)->picked(), 'The Place pick replaced the Thread pick.');
        $emptyBefore = clone $empty;
        $empty->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        $closed = $this->inScene('expedition');
        $closed->completeFlowStep('open', self::prompt('ok'), $this->snapshot(), self::at());
        $closed->closeThread($this->factThread($closed, 'expedition'));
        $closed->completeFlowStep('other', self::prompt('ok'), $this->snapshot(), self::at());
        $closedBefore = clone $closed;
        $closed->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertEquals($emptyBefore->threads(), $empty->threads());
        self::assertEquals($closedBefore->threads(), $closed->threads());
    }

    #[Test]
    public function closeThreadWithoutAFactStillClosesThePickedThreads(): void
    {
        $campaign = $this->inScene('closepicked');
        $campaign->addThread(ThreadId::fromString('t1'), 'Rob the vault', [], [], 1, $this->snapshot());
        $campaign->addThread(ThreadId::fromString('t2'), 'Find the ledger', [], [], 1, $this->snapshot());
        $campaign->completeFlowStep('lead', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault')]), $this->snapshot(), self::at());

        $campaign->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame([false, true], [$campaign->thread(ThreadId::fromString('t1'))?->isOpen(), $campaign->thread(ThreadId::fromString('t2'))?->isOpen()]);
    }

    #[Test]
    public function onlyOpenPlacesCarryingAllTheTagsAreCandidatesAndCounted(): void
    {
        $campaign = $this->inScene('count');
        $release = $this->snapshot();
        $campaign->addPlace(PlaceId::fromString('p1'), 'Saltmarsh', null, ['near'], [], 2, $release);
        $campaign->addPlace(PlaceId::fromString('p2'), 'Dunmere', null, ['near', 'old'], [], 1, $release);
        $campaign->addPlace(PlaceId::fromString('p3'), 'Free tag only', null, [], ['near'], 1, $release);
        $campaign->addPlace(PlaceId::fromString('p4'), 'Lost', null, ['near'], [], 1, $release);
        $campaign->closePlace(PlaceId::fromString('p4'));

        self::assertSame([['p1', 2], ['p2', 1]], array_map(static fn (\App\Play\Domain\Campaign\FlowRun\PickCandidate $item): array => [$item->id, $item->weight], $campaign->pickCandidates(ItemKind::Place, ['near'])->items));
        self::assertSame([3, 1, 0], [$campaign->openItemCount(ItemKind::Place, []), $campaign->openItemCount(ItemKind::Place, ['old']), $campaign->openItemCount(ItemKind::Thread, [])]);
        self::assertSame(['Saltmarsh', 'Dunmere'], $campaign->itemNames(ItemKind::Place, ['p1', 'p2']));
    }

    #[Test]
    public function aCountConditionOfPlacesChoosesTheBand(): void
    {
        foreach ([[0, 'none'], [1, 'one'], [2, 'many']] as [$places, $step]) {
            $campaign = $this->inScene('count');
            foreach ($places > 0 ? range(1, $places) : [] as $n) {
                $campaign->addPlace(PlaceId::fromString('p'.$n), 'Place '.$n, null, [], [], 1, $this->snapshot());
            }

            $campaign->completeFlowStep('start', self::prompt('ok'), $this->snapshot(), self::at());

            self::assertSame($step, self::flowRunOf($campaign)->stepKey());
        }
    }

    #[Test]
    public function aChosenPickOfPlacesTakesOpenCandidatesAndRefusesAThread(): void
    {
        $campaign = $this->inScene('pickplace');
        $campaign->addPlace(PlaceId::fromString('p1'), 'Saltmarsh', null, [], [], 1, $this->snapshot());
        $campaign->addPlace(PlaceId::fromString('p2'), 'Lost', null, [], [], 1, $this->snapshot());
        $campaign->closePlace(PlaceId::fromString('p2'));
        $before = clone $campaign;

        foreach ([[ItemKind::Place, 'p2', 'Pick step "where" has no candidate "p2".'], [ItemKind::Thread, 'p1', 'Step "where" picks places.']] as [$kind, $id, $message]) {
            try {
                $campaign->completeFlowStep('where', StepResult::pick($kind, [new PickedItem($id, 'X')]), $this->snapshot(), self::at());
                self::fail('The pick was accepted.');
            } catch (\Throwable $error) {
                self::assertSame($message, $error->getMessage());
            }
        }

        self::assertEquals($before, $campaign);

        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [new PickedItem('p1', 'Saltmarsh')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('fill', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame('Site: Saltmarsh', $campaign->flowRunView($this->snapshot())?->step?->title);
    }

    #[Test]
    public function aPlaceSlotTakesThePlaceCreatedOrPickedAndItsNameShowsInItsValue(): void
    {
        $created = $this->inScene('fillcreated');
        $created->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());
        $site = $this->slotFact($created, 'site');
        $place = $this->onlyPlace($created);

        self::assertSame([FactLink::place($place->id())->id, 'Keep'], [$site->subject()?->id, $created->factValues($this->snapshot())['site']]);
        $created->editPlace($place->id(), 'Keep of Saltmarsh', null, [], [], 1, $this->snapshot());
        self::assertSame('Keep of Saltmarsh', $created->factValues($this->snapshot())['site']);

        $picked = $this->inScene('pickplace');
        $picked->addPlace(PlaceId::fromString('p1'), 'Saltmarsh', null, [], [], 1, $this->snapshot());
        $picked->completeFlowStep('where', StepResult::pick(ItemKind::Place, [new PickedItem('p1', 'Saltmarsh')]), $this->snapshot(), self::at());
        $picked->completeFlowStep('fill', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame('p1', $this->slotFact($picked, 'site')->subject()?->id);
    }

    #[Test]
    public function aPlaceSlotIgnoresAPickedNpcAndAThreadSlotIgnoresAPickedPlace(): void
    {
        $campaign = $this->inScene('mismatch');
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->snapshot());
        $campaign->addPlace(PlaceId::fromString('p1'), 'Saltmarsh', null, [], [], 1, $this->snapshot());

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('fillsite', self::prompt('ok'), $this->snapshot(), self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [new PickedItem('p1', 'Saltmarsh')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('fillthread', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame([], $campaign->facts($this->snapshot()));
    }

    private function snapshot(): GameSystemSnapshot
    {
        return self::translate($this->content());
    }

    private function inScene(string $sceneType): Campaign
    {
        $release = $this->snapshot();
        $campaign = self::guided($release, 'fx');
        $campaign->startSession(self::at(), $release);
        $campaign->pickSceneType($sceneType, $release, self::at());

        return $campaign;
    }

    private function onlyPlace(Campaign $campaign): Place
    {
        self::assertCount(1, $campaign->places());

        return $campaign->places()[0];
    }

    private function slotFact(Campaign $campaign, string $slot): Fact
    {
        return array_find($campaign->facts($this->snapshot()), static fn (Fact $fact): bool => $fact->slot() === $slot) ?? self::fail('No fact in slot '.$slot);
    }

    private function factThread(Campaign $campaign, string $slot): ThreadId
    {
        return ThreadId::fromString($this->slotFact($campaign, $slot)->subject()->id ?? self::fail('The slot holds no Thread.'));
    }

    /**
     * @return list<string> the current scene's cast, as ids
     */
    private function cast(Campaign $campaign): array
    {
        return array_map(static fn (NpcId $id): string => $id->toString(), $campaign->currentScene()?->cast() ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $effects = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + ([] === $effects ? [] : ['effects' => $effects]);
        $pick = static fn (string $key, string $of, array $fields = []): array => ['key' => $key, 'kind' => 'pick', 'title' => $key, 'of' => $of, 'mode' => 'choose'] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        $sceneTypes = [
            $sceneType('make', [
                $pick('who', 'npc'),
                $prompt('ask', 'Where?', [['kind' => 'createPlace', 'name' => 'Camp at {answer}', 'tags' => ['near']]]),
                $prompt('show', 'Picked: {picked}'),
            ]),
            $sceneType('anon', [$prompt('make', 'Make', [['kind' => 'createPlace']])]),
            $sceneType('tagged', [$prompt('make', 'Make', [['kind' => 'createPlace', 'tags' => ['near']]])]),
            $sceneType('long', [$prompt('make', 'Make', [['kind' => 'createPlace', 'name' => '{answer}']])]),
            $sceneType('limit', [
                $pick('who', 'place'),
                $prompt('make', 'Make', [['kind' => 'createPlace', 'name' => 'One more']]),
                $prompt('show', 'Picked: {picked}'),
            ]),
            $sceneType('expedition', [
                $prompt('open', 'Open', [['kind' => 'createThread', 'name' => 'Expedition'], ['kind' => 'fillFact', 'slot' => 'expedition']]),
                $prompt('other', 'Other', [['kind' => 'createThread', 'name' => 'Side quest']]),
                $prompt('close', 'Close', [['kind' => 'closeThread', 'fact' => 'expedition']]),
            ]),
            $sceneType('closeslot', [$pick('lead', 'thread'), $pick('where', 'place'), $prompt('close', 'Close', [['kind' => 'closeThread', 'fact' => 'expedition']])]),
            $sceneType('closepicked', [$pick('lead', 'thread'), $prompt('close', 'Close', [['kind' => 'closeThread']])]),
            $sceneType('count', [
                $prompt('start', 'Start'),
                ['key' => 'how', 'kind' => 'condition', 'title' => 'How many sites?', 'count' => ['of' => 'place'], 'bands' => [['upTo' => 0, 'next' => 'none'], ['upTo' => 1, 'next' => 'one'], ['next' => 'many']]],
                $prompt('none', 'None'),
                $prompt('one', 'One'),
                $prompt('many', 'Many'),
            ]),
            $sceneType('pickplace', [
                $pick('where', 'place'),
                $prompt('fill', 'Fill', [['kind' => 'fillFact', 'slot' => 'site']]),
                $prompt('show', 'Site: {fact:site}'),
            ]),
            $sceneType('fillcreated', [
                $prompt('make', 'Make', [['kind' => 'createPlace', 'name' => 'Keep'], ['kind' => 'fillFact', 'slot' => 'site']]),
            ]),
            $sceneType('mismatch', [
                $pick('who', 'npc'),
                $prompt('fillsite', 'Fill site', [['kind' => 'fillFact', 'slot' => 'site']]),
                $pick('where', 'place'),
                $prompt('fillthread', 'Fill thread', [['kind' => 'fillFact', 'slot' => 'expedition']]),
            ]),
        ];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'places', 'name' => 'Places'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [['key' => 'site', 'label' => 'Site', 'type' => 'place'], ['key' => 'expedition', 'label' => 'Expedition', 'type' => 'thread']],
            'tags' => [['key' => 'near', 'label' => 'Near', 'appliesTo' => ['place']], ['key' => 'old', 'label' => 'Old']],
            'sceneTypes' => $sceneTypes,
            'flows' => [['key' => 'fx', 'name' => 'Effects', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_column($sceneTypes, 'key')]],
            ]]],
            'checks' => [],
        ];
    }
}
