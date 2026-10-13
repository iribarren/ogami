<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pick steps and count conditions over Characters (ADR 0022): every Character is a candidate and
 * counts (they have no open or closed state), release Tags filter as for NPCs, a pick in a scene
 * joins the Scene's cast, one in a hook also joins the Session's party. The test release has Tag
 * "crew" and Scene Types "crew" (a choose pick of one or two, then "{picked}"), "count" (a
 * condition over crew Characters) and "link" (a pick of Characters whose Effect fills an NPC slot),
 * and a world turn that picks a Character.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(Campaign::class)]
final class FlowRunCharacterPickTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function everyCharacterWithTheTagsIsACandidateInListOrder(): void
    {
        $campaign = $this->campaign();
        $campaign->addCharacter(CharacterId::fromString('plain'), 'Plain', [], ['crew'], [], $this->release);

        self::assertEquals([new PickCandidate('ada', 'Ada', 1), new PickCandidate('bo', 'Bo', 1)], $campaign->pickCandidates(ItemKind::Character, ['crew'])->items);
        self::assertSame(['ada', 'bo', 'cy', 'plain'], array_map(static fn (PickCandidate $item): string => $item->id, $campaign->pickCandidates(ItemKind::Character, [])->items), 'No tags: every Character; free tags never match.');
        self::assertSame([4, 2, 0], [$campaign->openItemCount(ItemKind::Character, []), $campaign->openItemCount(ItemKind::Character, ['crew']), $campaign->openItemCount(ItemKind::Npc, [])]);
    }

    #[Test]
    public function aPickInASceneJoinsTheScenesCastNotTheParty(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('crew', $this->release, self::at());

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('bo', 'Bo'), new PickedItem('ada', 'Ada')]), $this->release, self::at());

        self::assertSame([['bo', 'ada'], []], $this->joined($campaign));
        self::assertSame([['setup', 'shown'], ['who' => 'Bo, Ada'], 'Crew: Bo, Ada'], [self::position($campaign), self::flowRunOf($campaign)->answers(), $campaign->flowRunView($this->release)?->step?->title]);
    }

    #[Test]
    public function aPickNamesTheCharactersAsTheyAreNow(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('crew', $this->release, self::at());
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('ada', 'Ada')]), $this->release, self::at());
        $character = $campaign->character(CharacterId::fromString('ada')) ?? self::fail('Ada is held.');
        $campaign->editCharacter($character->id(), 'Ada Vega', $character->tags(), [], [], $this->release);

        self::assertSame('Crew: Ada Vega', $campaign->flowRunView($this->release)?->step?->title);
        self::assertSame([], $campaign->itemNames(ItemKind::Character, ['ghost']));
    }

    #[Test]
    public function aCharacterWithoutTheTagIsNotACandidate(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('crew', $this->release, self::at());
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('cy', 'Cy')]), $this->release, self::at());
            self::fail('The pick was accepted.');
        } catch (InvalidStepResult $e) {
            self::assertSame('Pick step "who" has no candidate "cy".', $e->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function aPickOfAnotherKindIsRefused(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('crew', $this->release, self::at());

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Step "who" picks characters.');

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
    }

    #[Test]
    public function aCountConditionCountsEveryCharacterWithTheTags(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('count', $this->release, self::at());

        self::assertSame([['setup', 'big'], 'Big crew'], [self::position($campaign), $campaign->flowRunView($this->release)?->step?->title]);
    }

    #[Test]
    public function aPickInAHookJoinsThePartyAndTheHookScenesCast(): void
    {
        $campaign = $this->atTheWorldTurn();
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('bo', 'Bo')]), $this->release, self::at());

        $this->assertJoined($campaign, ['bo'], ['bo']);
    }

    #[Test]
    public function aHookPickKeepsThePartyInOrderWithoutRepeats(): void
    {
        $campaign = $this->atTheWorldTurn(['ada']);
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('bo', 'Bo'), new PickedItem('ada', 'Ada')]), $this->release, self::at());

        $this->assertJoined($campaign, ['ada', 'bo'], ['ada', 'bo']);
    }

    #[Test]
    public function aPickedCharacterFillsNoFactSlot(): void
    {
        $campaign = $this->campaign();
        $campaign->pickSceneType('link', $this->release, self::at());

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Character, [new PickedItem('ada', 'Ada')]), $this->release, self::at());

        self::assertSame([], $campaign->facts($this->release), 'A Character is neither an NPC nor a Thread: nothing is linked.');
        self::assertSame([['ada'], []], [$this->joined($campaign)[0], $this->joined($campaign)[1]]);
    }

    /**
     * @return array{list<string>, list<string>} the current Scene's Characters and the session's party
     */
    private function joined(Campaign $campaign): array
    {
        return [$this->ids($campaign->currentScene()?->characters() ?? []), $this->ids($campaign->currentSession()?->party() ?? [])];
    }

    /**
     * @param list<CharacterId> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (CharacterId $id): string => $id->toString(), $ids);
    }

    /**
     * @param list<string> $party
     * @param list<string> $cast
     */
    private function assertJoined(Campaign $campaign, array $party, array $cast): void
    {
        self::assertSame([$cast, $party], $this->joined($campaign));
        self::assertSame(2, $campaign->currentScene()?->number(), 'The hook Scene holds the cast.');
    }

    /**
     * Ada, Bo (crew) and Cy are Characters before the session starts, so the first session's party is
     * empty unless $party names some.
     */
    private function campaign(): Campaign
    {
        $campaign = self::guided($this->release, 'play');
        foreach ([['ada', 'Ada', ['crew']], ['bo', 'Bo', ['crew']], ['cy', 'Cy', []]] as [$id, $name, $tags]) {
            $campaign->addCharacter(CharacterId::fromString($id), $name, $tags, [], [], $this->release);
        }

        $campaign->startSession(self::at(), $this->release);

        return $campaign;
    }

    /**
     * @param list<string> $party
     */
    private function atTheWorldTurn(array $party = []): Campaign
    {
        $campaign = $this->campaign();
        $campaign->editCurrentSessionParty(array_map(CharacterId::fromString(...), $party));
        $campaign->pickSceneType('quiet', $this->release, self::at());
        $campaign->completeFlowStep('quiet', self::prompt('ok'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'character-pick', 'name' => 'Character pick'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [['key' => 'ally', 'label' => 'Ally', 'type' => 'npc']],
            'tags' => [['key' => 'crew', 'label' => 'Crew']],
            'sceneTypes' => [
                $sceneType('crew', [
                    ['key' => 'who', 'kind' => 'pick', 'title' => 'Who?', 'of' => 'character', 'tags' => ['crew'], 'mode' => 'choose', 'count' => ['min' => 1, 'max' => 2]],
                    $prompt('shown', 'Crew: {picked}'),
                ]),
                $sceneType('count', [
                    ['key' => 'size', 'kind' => 'condition', 'title' => 'How big?', 'count' => ['of' => 'character', 'tags' => ['crew']], 'bands' => [['upTo' => 1, 'next' => 'small'], ['next' => 'big']]],
                    $prompt('small', 'Small crew', ['next' => 'end']),
                    $prompt('big', 'Big crew'),
                ]),
                $sceneType('link', [
                    ['key' => 'who', 'kind' => 'pick', 'title' => 'Who?', 'of' => 'character', 'mode' => 'choose', 'effects' => [['kind' => 'fillFact', 'slot' => 'ally']]],
                    $prompt('shown', 'Linked'),
                ]),
                $sceneType('quiet', [$prompt('quiet', 'Quiet')]),
            ],
            'flows' => [
                ['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                    ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['crew', 'count', 'link', 'quiet']], 'worldTurn' => [
                        ['key' => 'who', 'kind' => 'pick', 'title' => 'Who stirs?', 'of' => 'character', 'mode' => 'choose', 'count' => ['min' => 1, 'max' => 2]],
                        $prompt('after', 'Then {picked}'),
                    ]],
                ]],
            ],
            'checks' => [],
        ];
    }
}
