<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * fillFact Effects and {fact:key} placeholders while a FlowRun plays. Run on valid/v2-campaign-facts
 * (Fact slots city: text, prince: npc, goal: thread, ambition: text) with one flow playing its Scene
 * Type "scene" (steps city, prince, ambition), or on variants of it with other steps.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(FlowStepView::class)]
#[CoversClass(Campaign::class)]
final class FlowRunFactsTest extends FlowRunTestCase
{
    /**
     * @param ?list<array<string, mixed>> $setup other steps for the Scene Type, none for the fixture's own
     */
    private function snapshot(?array $setup = null): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('valid/v2-campaign-facts');
        $sceneTypes = $content['sceneTypes'];
        \assert(\is_array($sceneTypes) && \is_array($sceneTypes[0]));
        $content['flows'] = [['key' => 'facts', 'name' => 'Facts', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]],
        ]]];
        if (null !== $setup) {
            $sceneTypes[0]['setup'] = $setup;
            $sceneTypes[0]['play'] = [];
            $content['sceneTypes'] = $sceneTypes;
        }

        return self::translate($content);
    }

    private function inScene(GameSystemSnapshot $release): Campaign
    {
        $campaign = self::guided($release, 'facts');
        $campaign->startSession(self::at(), $release);

        return $campaign;
    }

    /**
     * @return array<string, Fact> the slot facts by slot key
     */
    private function slotFacts(Campaign $campaign, GameSystemSnapshot $release): array
    {
        $facts = [];
        foreach ($campaign->facts($release) as $fact) {
            if (null !== $fact->slot()) {
                $facts[$fact->slot()] = $fact;
            }
        }

        return $facts;
    }

    /**
     * @param list<array<string, mixed>> $effects
     *
     * @return array<string, mixed>
     */
    private function promptWith(array $effects, string $key = 'step'): array
    {
        return ['key' => $key, 'kind' => 'prompt', 'title' => 'Step '.$key, 'effects' => $effects];
    }

    /**
     * @return list<string>
     */
    private function fills(Campaign $campaign, GameSystemSnapshot $release): array
    {
        return array_map(static fn ($slot): string => $slot->key, $campaign->flowRunView($release)?->step->fills ?? []);
    }

    #[Test]
    public function aTextSlotTakesTheAnswerByDefaultUnderAGeneratedFactId(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());

        $city = $this->slotFacts($campaign, $release)['city'];
        self::assertSame([GeneratedItemId::fact('campaign-1', 0)->toString(), 'Prague', null, []], [$city->id()->toString(), $city->text(), $city->subject(), $city->links()]);
        self::assertSame(['city' => 'Prague', 'prince' => '', 'goal' => '', 'ambition' => ''], $campaign->factValues($release));
        self::assertSame('Rule of Prague', $campaign->flowRunView($release)?->step?->title);
    }

    #[Test]
    public function anNpcSlotTakesTheNpcTheStepCreatedAndARenameShowsInItsValue(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());

        $campaign->completeFlowStep('prince', self::prompt('Vlad'), $release, self::at());

        $npc = $campaign->npcs()[0];
        self::assertSame('Prince of Prague', $npc->name());
        $prince = $this->slotFacts($campaign, $release)['prince'];
        self::assertSame(FactLink::npc($npc->id())->id, $prince->subject()?->id);
        self::assertSame('Prince of Prague', $campaign->factValues($release)['prince']);

        $campaign->editNpc($npc->id(), 'Vlad the Younger', null, [], [], 1, $release);

        self::assertSame('Vlad the Younger', $campaign->factValues($release)['prince']);
        self::assertSame('Ambition', $campaign->flowRunView($release)?->step?->title);
    }

    #[Test]
    public function fillsInEffectOrderRenderPlaceholdersAndALaterFillReplacesTheEarlier(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());
        $campaign->completeFlowStep('prince', self::prompt('Vlad'), $release, self::at());

        // ambition: fillFact "{answer}, in {fact:city}", fillFact (answer), createThread "Reach {fact:ambition}", fillFact goal.
        $campaign->completeFlowStep('ambition', self::prompt('Glory'), $release, self::at());

        $facts = $this->slotFacts($campaign, $release);
        self::assertSame(['Glory', 4], [$facts['ambition']->text(), \count($campaign->facts($release))]);
        self::assertSame('Reach Glory', $campaign->threads()[0]->name());
        self::assertSame($campaign->threads()[0]->id()->toString(), $facts['goal']->subject()?->id);
        self::assertSame(GeneratedItemId::fact('campaign-1', 2)->toString(), $facts['ambition']->id()->toString());
    }

    #[Test]
    public function anExplicitTextIsRenderedTrimmedAndCutToTheFactTextLimit(): void
    {
        $release = $this->snapshot([
            $this->promptWith([['kind' => 'fillFact', 'slot' => 'city', 'text' => '  Seen: {answer}  ']], 'a'),
            $this->promptWith([['kind' => 'fillFact', 'slot' => 'ambition', 'text' => '{answer}{answer}']], 'b'),
        ]);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('a', self::prompt('Prague'), $release, self::at());
        $campaign->completeFlowStep('b', self::prompt(str_repeat('é', 1500)), $release, self::at());

        $facts = $this->slotFacts($campaign, $release);
        self::assertSame(['Seen: Prague', str_repeat('é', 2000)], [$facts['city']->text(), $facts['ambition']->text()]);
    }

    #[Test]
    public function anEmptyTextOrNothingPickedChangesNothingAndTheFlowGoesOn(): void
    {
        $release = $this->snapshot([
            $this->promptWith([['kind' => 'fillFact', 'slot' => 'city', 'text' => '{picked}'], ['kind' => 'fillFact', 'slot' => 'prince'], ['kind' => 'fillFact', 'slot' => 'goal']], 'a'),
            $this->promptWith([], 'b'),
        ]);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('a', self::prompt('Prague'), $release, self::at());

        self::assertSame([], $campaign->facts($release));
        self::assertSame(['setup', 'b'], self::position($campaign));
    }

    #[Test]
    public function aTextSlotWithoutTextAndWithoutAnAnswerChangesNothing(): void
    {
        // A pick that matched nothing has no answer text.
        $release = $this->snapshot([
            ['key' => 'p', 'kind' => 'pick', 'title' => 'Which lead?', 'of' => 'thread', 'mode' => 'random', 'mandatory' => false, 'effects' => [['kind' => 'fillFact', 'slot' => 'city']]],
            $this->promptWith([], 'b'),
        ]);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('p', StepResult::pick(ItemKind::Thread, []), $release, self::at());

        self::assertSame([], $campaign->facts($release), 'No slot is filled with the empty-answer placeholder.');
        self::assertSame(['setup', 'b'], self::position($campaign));
    }

    #[Test]
    public function aThreadSlotTakesTheFirstThreadPickedAndAnNpcSlotTakesNothingFromThreads(): void
    {
        $release = $this->snapshot([
            ['key' => 'p', 'kind' => 'pick', 'title' => 'Which lead?', 'of' => 'thread', 'mode' => 'choose', 'count' => ['min' => 1, 'max' => 2], 'effects' => [['kind' => 'fillFact', 'slot' => 'goal'], ['kind' => 'fillFact', 'slot' => 'prince']]],
        ]);
        $campaign = $this->inScene($release);
        $campaign->addThread(ThreadId::fromString('t1'), 'Vault', [], [], 1, $release);
        $campaign->addThread(ThreadId::fromString('t2'), 'Ledger', [], [], 1, $release);

        $campaign->completeFlowStep('p', StepResult::pick(ItemKind::Thread, [new PickedItem('t2', 'Ledger'), new PickedItem('t1', 'Vault')]), $release, self::at());

        $facts = $this->slotFacts($campaign, $release);
        self::assertSame(['goal'], array_keys($facts));
        self::assertSame(['t2', 'Ledger, Vault'], [$facts['goal']->subject()?->id, implode(', ', $campaign->itemNames(ItemKind::Thread, ['t2', 't1']))]);
        self::assertSame('Ledger', $campaign->factValues($release)['goal']);
    }

    #[Test]
    public function aRefillKeepsTheFactIdAndItsLinks(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene($release);
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $release);
        $campaign->fillFactSlot(FactId::fromString('fact-a'), 'city', FactFill::text('Ashford'), $release);
        $campaign->editFact(FactId::fromString('fact-a'), null, null, [FactLink::npc(NpcId::fromString('npc-1'))], $release);

        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());

        $city = $this->slotFacts($campaign, $release)['city'];
        self::assertSame(['fact-a', 'Prague', ['npc-1'], 1], [$city->id()->toString(), $city->text(), array_map(static fn (FactLink $link): string => $link->id, $city->links()), \count($campaign->facts($release))]);
    }

    #[Test]
    public function atTheFactLimitANewSlotFactIsNotCreatedButARefillStillHappens(): void
    {
        $release = $this->snapshot([
            $this->promptWith([['kind' => 'fillFact', 'slot' => 'city']], 'a'),
            $this->promptWith([['kind' => 'fillFact', 'slot' => 'ambition']], 'b'),
        ]);
        $campaign = $this->inScene($release);
        $campaign->fillFactSlot(FactId::fromString('fact-a'), 'city', FactFill::text('Ashford'), $release);
        for ($i = 2; $i <= Campaign::MAX_FACTS; ++$i) {
            $campaign->addFreeFact(FactId::fromString('fact-'.$i), 'Fact '.$i, 'Text', [], $release);
        }

        $campaign->completeFlowStep('a', self::prompt('Prague'), $release, self::at());
        $campaign->completeFlowStep('b', self::prompt('Glory'), $release, self::at());

        $facts = $this->slotFacts($campaign, $release);
        self::assertSame([Campaign::MAX_FACTS, ['city'], 'Prague'], [\count($campaign->facts($release)), array_keys($facts), $facts['city']->text()]);
        self::assertSame(['a' => 'Prague', 'b' => 'Glory'], self::flowRunOf($campaign)->answers(), 'The flow still went on.');
    }

    #[Test]
    public function aGeneratedFactIdAlreadyHeldFailsClosedAndNothingChanges(): void
    {
        $release = $this->snapshot([
            $this->promptWith([['kind' => 'createNpc', 'name' => 'Stranger'], ['kind' => 'fillFact', 'slot' => 'city']], 'a'),
            $this->promptWith([], 'b'),
        ]);
        $campaign = $this->inScene($release);
        // One fact already holds the id the next slot fact would get (the fact count is 1).
        $campaign->addFreeFact(GeneratedItemId::fact('campaign-1', 1), 'Squatter', 'Text', [], $release);
        $before = unserialize(serialize($campaign));

        try {
            $campaign->completeFlowStep('a', self::prompt('Prague'), $release, self::at());
            self::fail('The collision was accepted.');
        } catch (FactAlreadyExists) {
        }

        self::assertEquals($before, $campaign);
        self::assertSame([[], 1, ['setup', 'a']], [$campaign->npcs(), \count($campaign->facts($release)), self::position($campaign)]);
    }

    #[Test]
    public function aStepViewListsTheSlotsItsEffectsMayFillInReleaseOrderWithoutRepeats(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene($release);

        self::assertSame(['city'], $this->fills($campaign, $release));
        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());
        self::assertSame(['prince'], $this->fills($campaign, $release));
        $campaign->completeFlowStep('prince', self::prompt('Vlad'), $release, self::at());
        // ambition fills ambition twice, then goal: the release declares goal first.
        self::assertSame(['goal', 'ambition'], $this->fills($campaign, $release));
        $slot = $campaign->flowRunView($release)?->step?->fills[0];
        self::assertSame(['goal', 'Goal', 'thread', null], [$slot?->key, $slot?->label, $slot?->type->value, $slot?->hint]);
    }

    #[Test]
    public function theSlotsOfOptionsBandsTableEntriesAndOutcomesAreListedToo(): void
    {
        $release = $this->snapshot([
            ['key' => 'c', 'kind' => 'choice', 'title' => 'Pick', 'effects' => [['kind' => 'fillFact', 'slot' => 'city']], 'options' => [
                ['key' => 'left', 'label' => 'Left', 'effects' => [['kind' => 'fillFact', 'slot' => 'ambition']]],
                ['key' => 'right', 'label' => 'Right'],
            ]],
            ['key' => 't', 'kind' => 'table', 'title' => 'Rumour', 'table' => 'rumours', 'otherwise' => ['effects' => [['kind' => 'fillFact', 'slot' => 'prince']]]],
            ['key' => 'r', 'kind' => 'roll', 'title' => 'Roll', 'dice' => '1d6', 'bands' => [['upTo' => 3, 'effects' => [['kind' => 'fillFact', 'slot' => 'ambition']]], ['effects' => [['kind' => 'fillFact', 'slot' => 'city']]]]],
        ]);
        $campaign = $this->inScene($release);

        self::assertSame(['city', 'ambition'], $this->fills($campaign, $release));
        $campaign->completeFlowStep('c', StepResult::choice('right'), $release, self::at());
        self::assertSame(['prince', 'goal'], $this->fills($campaign, $release), 'The otherwise outcome and the rumours table entry.');
        $campaign->completeFlowStep('t', self::table('rumours', 'Nothing', 2), $release, self::at());
        self::assertSame(['city', 'ambition'], $this->fills($campaign, $release));
    }

    #[Test]
    public function aStepWithoutFillFactEffectsFillsNothing(): void
    {
        $release = $this->snapshot([$this->promptWith([['kind' => 'createNpc', 'name' => 'X']])]);

        self::assertSame([], $this->fills($this->inScene($release), $release));
    }
}
