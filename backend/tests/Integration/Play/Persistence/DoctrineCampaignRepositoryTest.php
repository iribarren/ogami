<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowNote;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\ShopStock;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\ConditionOption;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignCharactersType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignFactsType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignFlowRunType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignNpcsType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignSessionsType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignThreadsType;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Unit\Play\Domain\Campaign\Upgrade\UpgradeFixtures;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(CampaignSessionsType::class)]
#[CoversClass(CampaignFlowRunType::class)]
#[CoversClass(CampaignNpcsType::class)]
#[CoversClass(CampaignThreadsType::class)]
#[CoversClass(CampaignFactsType::class)]
final class DoctrineCampaignRepositoryTest extends KernelTestCase
{
    use CampaignRepositoryContract;

    private DoctrineCampaignRepository $repository;
    private EntityManagerInterface $entityManager;
    private ?EntityManagerInterface $otherEntityManager = null;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->repository = $container->get(DoctrineCampaignRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function campaigns(): CampaignRepository
    {
        return $this->repository;
    }

    protected function forgetLoaded(): void
    {
        $this->entityManager->clear();
        $this->otherEntityManager?->clear();
    }

    /**
     * A second entity manager on the same connection (so inside the test's transaction): what
     * another request, with its own unit of work, would load and save.
     */
    protected function campaignsElsewhere(): CampaignRepository
    {
        $this->otherEntityManager ??= new EntityManager(
            $this->entityManager->getConnection(),
            $this->entityManager->getConfiguration(),
        );

        return new DoctrineCampaignRepository($this->otherEntityManager);
    }

    #[Test]
    public function itIsThePortAdapter(): void
    {
        self::assertSame($this->repository, self::getContainer()->get(CampaignRepository::class));
    }

    #[Test]
    public function aDuplicateOfACampaignStillLoadedIsRejectedToo(): void
    {
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));

        $this->expectException(CampaignAlreadyExists::class);

        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OTHER_OWNER, '2026-10-07'));
    }

    #[Test]
    public function theFlowRunIsStoredAsJsonbAndACampaignPlayedFreelyHasNone(): void
    {
        $this->repository->add($this->guidedCampaign('01890a5d-ac96-774b-bcce-b302099a8057'));
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-06'));

        $connection = $this->entityManager->getConnection();
        self::assertSame('jsonb', $connection->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'flow_run'"));
        self::assertEquals([
            'status' => 'paused', 'phaseIndex' => 0, 'stage' => 'scene', 'sessionNumber' => 1, 'sceneNumber' => 1, 'sceneType' => 'legwork',
            'part' => 'open', 'stepKey' => null, 'sequencePosition' => 0, 'scenesPlayed' => 1, 'answers' => [], 'forcedNextSceneType' => null,
            'phaseEnding' => null, 'switchCount' => 0, 'hook' => null, 'phaseOpened' => true, 'sessionOpeningDue' => false, 'picked' => null,
            'autoPickDue' => false, 'history' => [['event' => 'paused', 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => []]],
            'pendingCheck' => null, 'shopStock' => null,
        ], $this->storedFlowRun('01890a5d-ac96-774b-bcce-b302099a8057'));
        self::assertNull($connection->fetchOne("SELECT flow_run FROM play_campaign WHERE id = '01890a5d-ac96-774b-bcce-b302099a8058'"));
    }

    #[Test]
    public function theNotesOfTheFlowAreNeverStored(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->guidedCampaign($id->toString());
        $campaign->noteFlow(FlowNote::band('Strong hit'));
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertSame([], $loaded?->takeFlowNotes());
        self::assertEquals($this->guidedCampaign($id->toString())->flowRun(), $loaded->flowRun());
    }

    #[Test]
    public function aCampaignStoredWithAFlowAndNoFlowRunLoadsGuided(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-06'));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = NULL WHERE id = ?', [$id->toString()]);
        $this->forgetLoaded();

        $legacy = $this->repository->ofId($id);

        self::assertSame(['one-shot', FlowRunStatus::Active, FlowRunStage::ScenePick], [$legacy?->flowKey(), $legacy?->flowRun()?->status(), $legacy?->flowRun()?->stage()]);
        self::assertNull($this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058'))?->flowRun());

        $this->forgetLoaded();
        self::assertSame($id->toString(), $this->repository->ownedBy(self::OWNER)[0]->id()->toString());
        self::assertNotNull($this->repository->ofId($id)?->flowRun());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedFlowRuns(): iterable
    {
        $entry = static fn (string $event, array $details): array => ['history' => [['event' => $event, 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => $details]]];

        yield 'unknown status' => [['status' => 'lost'], 'Stored campaign FlowRun: "status" must be one of "active", "paused", "completed".'];
        yield 'no phase index' => [['phaseIndex' => null], 'Stored campaign FlowRun: "phaseIndex" must be an integer.'];
        yield 'unknown stage' => [['stage' => 'interlude'], 'Stored campaign FlowRun: "stage" must be one of "scenePick", "scene", "hook".'];
        yield 'stage hook without a hook' => [['stage' => 'hook'], 'Stored campaign FlowRun: "hook" must be set at stage "hook".'];
        yield 'a hook outside stage hook' => [['hook' => 'worldTurn'], 'Stored campaign FlowRun: "hook" must be null outside stage "hook".'];
        yield 'unknown hook' => [['stage' => 'hook', 'hook' => 'dawn'], 'Stored campaign FlowRun: "hook" must be one of "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
        yield 'phase opened not a boolean' => [['phaseOpened' => 1], 'Stored campaign FlowRun: "phaseOpened" must be a boolean.'];
        yield 'session opening due null' => [['sessionOpeningDue' => null], 'Stored campaign FlowRun: "sessionOpeningDue" must be a boolean.'];
        yield 'auto pick due not a boolean' => [['autoPickDue' => 'yes'], 'Stored campaign FlowRun: "autoPickDue" must be a boolean.'];
        yield 'unknown part' => [['part' => 'epilogue'], 'Stored campaign FlowRun: "part" must be one of "sceneOpening", "setup", "play", "open", "closing", "sceneClosing".'];
        yield 'scene number not an integer' => [['sceneNumber' => '1'], 'Stored campaign FlowRun: "sceneNumber" must be an integer.'];
        yield 'answer not a string' => [['answers' => ['plan' => 3]], 'Stored campaign FlowRun: "answers" must be an object of strings.'];
        yield 'unknown phase ending' => [['phaseEnding' => 'later'], 'Stored campaign FlowRun: "phaseEnding" must be one of "endPhase", "moveOn", "finished".'];
        yield 'picked not an object' => [['picked' => 'Ana'], 'Stored campaign FlowRun: "kind" must be one of "npc", "thread", "character".'];
        yield 'picked of an unknown kind' => [['picked' => ['kind' => 'faction', 'ids' => ['a']]], 'Stored campaign FlowRun: "kind" must be one of "npc", "thread", "character".'];
        yield 'picked ids not a list' => [['picked' => ['kind' => 'npc', 'ids' => 'a']], 'Stored campaign FlowRun: "picked" must be a list.'];
        yield 'a picked id not a string' => [['picked' => ['kind' => 'npc', 'ids' => [1]]], 'Stored campaign FlowRun: "picked" must be a list of id strings.'];
        yield 'picked without ids' => [['picked' => ['kind' => 'npc', 'ids' => []]], 'Stored campaign FlowRun: "picked" is invalid: Picked items hold 1 to 10 ids, got 0.'];
        yield 'picked with a repeated id' => [['picked' => ['kind' => 'npc', 'ids' => ['a', 'a']]], 'Stored campaign FlowRun: "picked" is invalid: Picked item ids must be distinct and not blank.'];
        yield 'picked with more than ten ids' => [['picked' => ['kind' => 'npc', 'ids' => array_map(strval(...), range(1, 11))]], 'Stored campaign FlowRun: "picked" is invalid: Picked items hold 1 to 10 ids, got 11.'];
        yield 'picked outside a Scene' => [['picked' => ['kind' => 'npc', 'ids' => ['a']], 'sceneNumber' => null], 'Stored campaign FlowRun: "picked" must be null outside a Scene.'];
        $pending = ['stepKey' => 'case', 'entryId' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'characterId' => 'ada', 'difficulty' => null, 'bandKey' => 'weak'];
        $active = ['status' => 'active', 'part' => 'setup', 'stepKey' => 'case'];
        yield 'a pending check that is no object' => [['pendingCheck' => 'weak'] + $active, 'Stored campaign FlowRun: "pendingCheck" must be an object or null.'];
        yield 'a pending check without an entry' => [['pendingCheck' => ['entryId' => null] + $pending] + $active, 'Stored campaign FlowRun: "entryId" must be a string.'];
        yield 'a pending check with a difficulty that is no integer' => [['pendingCheck' => ['difficulty' => '13'] + $pending] + $active, 'Stored campaign FlowRun: "difficulty" must be an integer.'];
        yield 'a pending check with a blank band' => [['pendingCheck' => ['bandKey' => ' '] + $pending] + $active, 'Stored campaign FlowRun: "pendingCheck" is invalid: A pending check needs a bandKey.'];
        yield 'a pending check with a check key that is no string' => [['pendingCheck' => ['checkKey' => 5] + $pending] + $active, 'Stored campaign FlowRun: "checkKey" must be a string.'];
        yield 'a pending check with a special result that is no string' => [['pendingCheck' => ['special' => 5] + $pending] + $active, 'Stored campaign FlowRun: "special" must be a string.'];
        yield 'a pending check with an unknown special result' => [['pendingCheck' => ['special' => 'frenzy'] + $pending] + $active, 'Stored campaign FlowRun: "special" must be one of "critical", "messyCritical", "bestialFailure".'];
        yield 'a pending check with a blank check key' => [['pendingCheck' => ['checkKey' => ' '] + $pending] + $active, 'Stored campaign FlowRun: "pendingCheck" is invalid: A pending check needs a checkKey.'];
        yield 'a pending check of another step' => [['pendingCheck' => $pending] + ['stepKey' => 'plan'] + $active, 'Stored campaign FlowRun: "pendingCheck" must be the current step\'s, while guidance is active.'];
        yield 'a pending check while guidance is paused' => [['pendingCheck' => $pending] + ['status' => 'paused'] + $active, 'Stored campaign FlowRun: "pendingCheck" must be the current step\'s, while guidance is active.'];
        $stock = ['stepKey' => 'case', 'gear' => ['knife', 'rope']];
        yield 'a shop stock that is no object' => [['shopStock' => ['knife']] + $active, 'Stored campaign FlowRun: "shopStock" must be an object or null.'];
        yield 'a shop stock whose gear is no list' => [['shopStock' => ['gear' => 'knife'] + $stock] + $active, 'Stored campaign FlowRun: "shopStock" must be a list.'];
        yield 'a shop stock with a gear that is no string' => [['shopStock' => ['gear' => [1]] + $stock] + $active, 'Stored campaign FlowRun: "shopStock" must be a list of gear keys.'];
        yield 'a shop stock with a repeated gear' => [['shopStock' => ['gear' => ['knife', 'knife']] + $stock] + $active, 'Stored campaign FlowRun: "shopStock" is invalid: The stock of shop step "case" lists distinct catalog keys.'];
        yield 'a shop stock of another step' => [['shopStock' => $stock] + ['stepKey' => 'plan'] + $active, 'Stored campaign FlowRun: "shopStock" must be the current step\'s.'];
        yield 'history not a list' => [['history' => 'none'], 'Stored campaign FlowRun: "history" must be a list.'];
        yield 'unknown event' => [$entry('teleported', []), 'Stored campaign FlowRun: "event" must be one of "skip", "trackerEdit", "sceneTypeSwitch", "sceneTypeSwitchIgnored", "paused", "resumed", "sceneAbandoned", "phaseEnded", "completed".'];
        yield 'malformed time' => [['history' => [['event' => 'paused', 'at' => 'yesterday', 'details' => []]]], 'Stored campaign FlowRun: "at" must be a time like 2026-10-06T10:00:00.000000+00:00.'];
        yield 'skip of an unknown part' => [$entry('skip', ['step' => 'plan', 'part' => 'later']), 'Stored campaign FlowRun: "part" must be one of "sceneOpening", "setup", "play", "open", "closing", "sceneClosing", "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
        yield 'tracker edit from a string' => [$entry('trackerEdit', ['tracker' => 'heat', 'from' => '1', 'to' => 2]), 'Stored campaign FlowRun: "from" must be an integer.'];
        yield 'switch without its target' => [$entry('sceneTypeSwitch', ['scene' => 1, 'from' => null]), 'Stored campaign FlowRun: "to" must be a string.'];
        yield 'ignored switch without its Scene Type' => [$entry('sceneTypeSwitchIgnored', ['scene' => 1]), 'Stored campaign FlowRun: "sceneType" must be a string.'];
        yield 'abandoned scene without its session' => [$entry('sceneAbandoned', ['scene' => 1]), 'Stored campaign FlowRun: "session" must be an integer.'];
        yield 'phase ended for an unknown reason' => [$entry('phaseEnded', ['phase' => 'plan', 'reason' => 'boredom']), 'Stored campaign FlowRun: "reason" must be one of "finished", "moveOn", "endPhase".'];
    }

    /**
     * @param array<string, mixed> $fields replace the stored FlowRun's fields
     */
    #[Test]
    #[DataProvider('malformedFlowRuns')]
    public function aMalformedStoredFlowRunFailsToLoad(array $fields, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($fields + $this->storedFlowRun($id->toString()), \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    /**
     * A FlowRun stored before hooks ran (play-flow-run slice 20) has no hook, phase opening or
     * session opening fields: the current phase's opening has run and no session opening is due.
     */
    #[Test]
    public function aFlowRunStoredBeforeHooksReadsAsPastThePhaseOpening(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['hook', 'phaseOpened', 'sessionOpeningDue', 'picked']));
        $legacy['history'] = [['event' => 'skip', 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => ['step' => 'plan', 'part' => 'setup']]];
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $flowRun = $this->repository->ofId($id)?->flowRun();

        self::assertSame([FlowRunStage::Scene, null, true, false, null, 'setup'], [$flowRun?->stage(), $flowRun?->hook(), $flowRun?->phaseOpened(), $flowRun?->sessionOpeningDue(), $flowRun?->picked(), array_last($flowRun?->history() ?? [])?->details['part']]);
    }

    /**
     * A FlowRun stored before the scene pick waited after a scene (preset-mythic-flow slice 3, ADR
     * 0020) has no autoPickDue: it reads as waiting, so no scene starts by surprise.
     */
    #[Test]
    public function aFlowRunStoredBeforeThePickWaitedReadsAsWaiting(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['autoPickDue']));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        self::assertFalse($this->repository->ofId($id)?->flowRun()?->autoPickDue());
    }

    /**
     * The progress holds the stored scene number; a FlowRun stored without one (outside a scene, or
     * before it was kept) reads a null scene number, so the guide shows no scene tips. A paused
     * FlowRun keeps the stored number but reports none (see FlowRunViewTest).
     */
    #[Test]
    public function theProgressOfAStoredFlowRunReadsItsSceneNumberAndALegacyOneReadsNull(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $campaign = $this->guidedCampaign($id->toString());
        $campaign->resumeGuidance($release, new \DateTimeImmutable('2026-10-10T09:03:00+00:00'));
        $this->repository->add($campaign);
        $this->forgetLoaded();

        self::assertSame(1, $this->repository->ofId($id)?->flowRunView($release)?->progress?->sceneNumber);

        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['sceneNumber']));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        self::assertNull($this->repository->ofId($id)?->flowRunView($release)?->progress?->sceneNumber);
    }

    /**
     * A FlowRun stored before check step rolls could pend (rules-dice-pools slice 5, ADR 0041) has
     * no pendingCheck: it reads as none.
     */
    #[Test]
    public function aFlowRunStoredBeforePendingChecksReadsNone(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['pendingCheck']));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNull($loaded?->flowRun()?->pendingCheck());
        self::assertEquals($this->guidedCampaign($id->toString())->flowRun(), $loaded?->flowRun());
    }

    /**
     * A FlowRun stored before shops (play-items-economy slice 4, ADR 0045) has no shopStock: it reads as none.
     */
    #[Test]
    public function aFlowRunStoredBeforeShopsReadsNoStock(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['shopStock']));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNull($loaded?->flowRun()?->shopStock());
        self::assertEquals($this->guidedCampaign($id->toString())->flowRun(), $loaded?->flowRun());
    }

    #[Test]
    public function aShopStockRoundTripsWithTheWholeFlowRun(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $base = $this->guidedCampaign($id->toString());
        $run = $base->flowRun() ?? throw new \LogicException('No FlowRun.');
        $stock = new ShopStock('market', ['knife', 'eye']);
        $flowRun = FlowRun::reconstitute($run->status(), $run->phaseIndex(), FlowRunStage::Scene, 1, 1, 'legwork', ScenePart::Play, 'market', $run->sequencePosition(), $run->scenesPlayed(), $run->answers(), null, null, $run->switchCount(), $run->history(), null, $run->phaseOpened(), $run->sessionOpeningDue(), shopStock: $stock);
        $campaign = Campaign::reconstitute($id, $base->ownerId(), $base->name(), $base->pinnedRelease(), $base->createdAt(), $base->sessions(), [], $base->flowKey(), $flowRun);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals($stock, $loaded?->flowRun()?->shopStock());
        self::assertEquals(['stepKey' => 'market', 'gear' => ['knife', 'eye']], $this->storedFlowRun($id->toString())['shopStock']);
    }

    #[Test]
    #[DataProvider('pendingChecks')]
    public function aPendingCheckRoundTripsWithTheWholeFlowRun(?string $checkKey): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $pending = new PendingCheck('case', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'ada', 13, 'weak', $checkKey);
        $campaign = $this->withPendingCheck($id, $pending);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals($pending, $loaded?->flowRun()?->pendingCheck());
        self::assertEquals(['stepKey' => 'case', 'entryId' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'characterId' => 'ada', 'difficulty' => 13, 'bandKey' => 'weak', 'checkKey' => $checkKey, 'special' => null], $this->storedFlowRun($id->toString())['pendingCheck']);
    }

    #[Test]
    public function aPendingCheckKeepsItsSpecialResult(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $pending = new PendingCheck('case', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'ada', 13, 'miss', special: SpecialResult::BestialFailure);
        $this->repository->add($this->withPendingCheck($id, $pending));
        $this->forgetLoaded();

        self::assertEquals($pending, $this->repository->ofId($id)?->flowRun()?->pendingCheck());
        $stored = $this->storedFlowRun($id->toString())['pendingCheck'];
        self::assertIsArray($stored);
        self::assertSame('bestialFailure', $stored['special'] ?? null);
    }

    /**
     * A pending roll stored before special results (ADR 0044) has no special: it reads without one.
     */
    #[Test]
    public function aPendingCheckStoredBeforeSpecialResultsReadsWithoutOne(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $pending = new PendingCheck('case', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'ada', 13, 'weak');
        $this->repository->add($this->withPendingCheck($id, $pending));
        $legacy = $this->storedFlowRun($id->toString());
        $stored = $legacy['pendingCheck'];
        self::assertIsArray($stored);
        unset($stored['special']);
        $legacy['pendingCheck'] = $stored;
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        self::assertEquals($pending, $this->repository->ofId($id)?->flowRun()?->pendingCheck());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function pendingChecks(): iterable
    {
        yield 'of a single-check step, which keeps no check' => [null];
        yield 'of a check set, which keeps the picked check' => ['nerve'];
    }

    /**
     * A pending roll stored before check sets (ADR 0042) has no checkKey: it reads as a roll of the
     * step's own check.
     */
    #[Test]
    public function aPendingCheckStoredBeforeCheckSetsReadsWithoutACheckKey(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $pending = new PendingCheck('case', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001', 'ada', 13, 'weak');
        $this->repository->add($this->withPendingCheck($id, $pending));
        $legacy = $this->storedFlowRun($id->toString());
        $stored = $legacy['pendingCheck'];
        self::assertIsArray($stored);
        unset($stored['checkKey']);
        $legacy['pendingCheck'] = $stored;
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($pending, $loaded?->flowRun()?->pendingCheck());
        self::assertNull($loaded?->flowRun()?->pendingCheck()?->checkKey);
    }

    private function withPendingCheck(CampaignId $id, PendingCheck $pending): Campaign
    {
        $base = $this->guidedCampaign($id->toString());
        $run = $base->flowRun() ?? throw new \LogicException('No FlowRun.');
        $flowRun = FlowRun::reconstitute(FlowRunStatus::Active, $run->phaseIndex(), FlowRunStage::Scene, 1, 1, 'legwork', ScenePart::Setup, 'case', $run->sequencePosition(), $run->scenesPlayed(), $run->answers(), null, null, $run->switchCount(), $run->history(), null, $run->phaseOpened(), $run->sessionOpeningDue(), null, false, $pending);

        return Campaign::reconstitute($id, $base->ownerId(), $base->name(), $base->pinnedRelease(), $base->createdAt(), $base->sessions(), [], $base->flowKey(), $flowRun);
    }

    #[Test]
    public function aDueAutoPickRoundTripsWithTheWholeFlowRun(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $base = $this->guidedCampaign($id->toString());
        $run = $base->flowRun() ?? throw new \LogicException('No FlowRun.');
        $flowRun = FlowRun::reconstitute($run->status(), $run->phaseIndex(), FlowRunStage::ScenePick, null, null, null, null, null, $run->sequencePosition(), $run->scenesPlayed(), $run->answers(), 'legwork', $run->phaseEndingReason(), $run->switchCount(), $run->history(), null, $run->phaseOpened(), $run->sessionOpeningDue(), null, true);
        $campaign = Campaign::reconstitute($id, $base->ownerId(), $base->name(), $base->pinnedRelease(), $base->createdAt(), $base->sessions(), [], $base->flowKey(), $flowRun);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertTrue($loaded?->flowRun()?->autoPickDue());
        self::assertTrue($this->storedFlowRun($id->toString())['autoPickDue']);
    }

    #[Test]
    #[DataProvider('pickedKinds')]
    public function thePickedItemsRoundTripWithTheWholeFlowRun(ItemKind $kind): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $base = $this->guidedCampaign($id->toString());
        $run = $base->flowRun() ?? throw new \LogicException('No FlowRun.');
        $flowRun = FlowRun::reconstitute($run->status(), $run->phaseIndex(), $run->stage(), $run->scene()[0] ?? null, $run->scene()[1] ?? null, $run->sceneType(), $run->part(), $run->stepKey(), $run->sequencePosition(), $run->scenesPlayed(), $run->answers(), $run->forcedNextSceneType(), $run->phaseEndingReason(), $run->switchCount(), $run->history(), $run->hook(), $run->phaseOpened(), $run->sessionOpeningDue(), new PickedItems($kind, ['t2', 't1']));
        $campaign = Campaign::reconstitute($id, $base->ownerId(), $base->name(), $base->pinnedRelease(), $base->createdAt(), $base->sessions(), [], $base->flowKey(), $flowRun);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals(new PickedItems($kind, ['t2', 't1']), $loaded?->flowRun()?->picked());
        self::assertEquals(['kind' => $kind->value, 'ids' => ['t2', 't1']], $this->storedFlowRun($id->toString())['picked']);
    }

    /**
     * @return iterable<string, array{ItemKind}>
     */
    public static function pickedKinds(): iterable
    {
        yield 'Threads' => [ItemKind::Thread];
        yield 'Characters' => [ItemKind::Character];
    }

    #[Test]
    public function aPickInAHookRoundTripsWithItsHookSceneAndTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $at = new \DateTimeImmutable('2026-10-10T09:00:00+00:00');
        $step = static fn (string $key, string $kind, array $fields = []): array => ['key' => $key, 'kind' => $kind, 'title' => $key] + $fields;
        $release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of([
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'hook-pick', 'name' => 'Hook pick'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [['key' => 'rival', 'label' => 'Rival']],
            'sceneTypes' => [['key' => 'quiet', 'name' => 'Quiet', 'purpose' => 'Quiet.', 'oracles' => [], 'setup' => [$step('quiet', 'prompt')], 'play' => [], 'closing' => []]],
            'flows' => [['key' => 'turns', 'name' => 'Turns', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['quiet']], 'worldTurn' => [$step('who', 'pick', ['of' => 'npc', 'mode' => 'choose']), $step('after', 'prompt')]],
            ]]],
            'checks' => [],
        ]));
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('hook-pick', 1, 'Hook pick'), $at, [], $release->flow('turns'));
        $campaign->startSession($at, $release);
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $release);
        $campaign->completeFlowStep('quiet', StepResult::prompt('ok'), $release, $at);
        $campaign->endFlowScene(1, $release, $at);
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $release, $at);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals([FlowRunStage::Hook, SceneKind::Hook, new PickedItems(ItemKind::Npc, ['ana'])], [$loaded?->flowRun()?->stage(), $loaded?->currentScene()?->kind(), $loaded?->flowRun()?->picked()]);
    }

    #[Test]
    public function aCharacterStepInAHookRoundTripsWithItsAnswerThePartyAndTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $at = new \DateTimeImmutable('2026-10-10T09:00:00+00:00');
        $step = static fn (string $key, string $kind, array $fields = []): array => ['key' => $key, 'kind' => $kind, 'title' => $key] + $fields;
        $release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of([
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'hook-hero', 'name' => 'Hook hero'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [],
            'sceneTypes' => [['key' => 'quiet', 'name' => 'Quiet', 'purpose' => 'Quiet.', 'oracles' => [], 'setup' => [$step('quiet', 'prompt')], 'play' => [], 'closing' => []]],
            'flows' => [['key' => 'turns', 'name' => 'Turns', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['quiet']], 'worldTurn' => [$step('hero', 'character'), $step('after', 'prompt', ['title' => 'Hello {step:hero}'])]],
            ]]],
            'checks' => [],
        ]));
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('hook-hero', 1, 'Hook hero'), $at, [], $release->flow('turns'));
        $campaign->addCharacter(CharacterId::fromString('ada'), 'Ada', [], [], [], $release);
        $campaign->startSession($at, $release);
        $campaign->completeFlowStep('quiet', StepResult::prompt('ok'), $release, $at);
        $campaign->endFlowScene(1, $release, $at);
        $campaign->completeFlowStep('hero', StepResult::character('ada'), $release, $at);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals(['quiet' => 'ok', 'hero' => 'Ada'], $loaded?->flowRun()?->answers());
        self::assertSame(['ada'], array_map(static fn (CharacterId $character): string => $character->toString(), $loaded?->currentSession()?->party() ?? []));
        $stored = $this->storedFlowRun($id->toString())['answers'];
        self::assertIsArray($stored);
        ksort($stored);
        self::assertSame(['hero' => 'Ada', 'quiet' => 'ok'], $stored, 'JSONB does not keep the key order.');
    }

    /**
     * @return array<mixed> the stored FlowRun column, decoded
     */
    private function storedFlowRun(string $id): array
    {
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT flow_run FROM play_campaign WHERE id = ?', [$id]);
        self::assertIsString($stored);
        $decoded = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * A campaign along Flow "one-shot" in open play of its first scene, guidance paused.
     */
    private function guidedCampaign(string $id): Campaign
    {
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $campaign = Campaign::create(CampaignId::fromString($id), self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], $release->flow('one-shot'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), $release);
        $campaign->pickSceneType('legwork', $release, new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->pauseGuidance(new \DateTimeImmutable('2026-10-10T09:02:00+00:00'));

        return $campaign;
    }

    #[Test]
    public function sessionsAreStoredAsJsonbAndTimestampsWithTheirTimeZone(): void
    {
        $campaign = $this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06');
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T10:05:00+02:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T10:06:00+02:00'));
        $this->repository->add($campaign);

        $connection = $this->entityManager->getConnection();
        $types = $connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name IN ('sessions', 'created_at') ORDER BY column_name",
        );
        self::assertSame(['created_at' => 'timestamp with time zone', 'sessions' => 'jsonb'], $types);

        $sessions = $connection->fetchOne(
            "SELECT sessions FROM play_campaign WHERE id = '01890a5d-ac96-774b-bcce-b302099a8057'",
        );
        self::assertIsString($sessions);
        self::assertEquals(
            [['number' => 1, 'startedAt' => '2026-10-06T10:05:00.000000+02:00', 'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-06T10:06:00.000000+02:00', 'kind' => 'scene', 'sceneType' => null, 'hook' => null, 'cast' => [], 'characters' => [], 'defaultTitle' => false]], 'endedAt' => null, 'party' => []]],
            json_decode($sessions, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function aSceneStoredBeforeScenesHadAKindReadsAsASceneOfPlayWithoutASceneType(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00"}]}]');

        $scene = $this->repository->ofId($id)?->currentScene();

        self::assertNotNull($scene);
        self::assertSame(['At the gate', SceneKind::Scene, null, null, []], [$scene->title(), $scene->kind(), $scene->sceneType(), $scene->hook(), $scene->cast()]);
    }

    #[Test]
    public function aSceneStoredBeforeTheDefaultTitleRecordHasNoneAndTheRecordRoundTrips(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "Legwork 2", "startedAt": "2026-10-06T10:06:00.000000+02:00", "sceneType": "legwork"}]}]');
        $legacy = $this->repository->ofId($id) ?? self::fail('The campaign was not stored.');
        self::assertNull($legacy->currentScene()?->hasDefaultTitle());

        $legacy->startScene(null, new \DateTimeImmutable('2026-10-06T10:07:00+02:00'), new SceneType('firefight', 'Firefight', 'A purpose.', null, [], new StepList(), new StepList(), new StepList()));
        $legacy->startScene('Given', new \DateTimeImmutable('2026-10-06T10:08:00+02:00'));
        $this->repository->save($legacy);
        $this->entityManager->clear();

        $loaded = $this->repository->ofId($id) ?? self::fail('The campaign was not stored.');
        self::assertEquals($legacy->sessions(), $loaded->sessions());
        self::assertSame([null, true, false], array_map(static fn (Scene $scene): ?bool => $scene->hasDefaultTitle(), $loaded->sessions()[0]->scenes()));
    }

    #[Test]
    public function aSessionStoredBeforeSessionsEndedIsUnderWayAndACampaignStoredBeforeFlowsPlaysFreely(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": []}]');

        $campaign = $this->repository->ofId($id);

        self::assertNotNull($campaign);
        self::assertSame(1, $campaign->currentSession()?->number());
        self::assertNull($campaign->sessions()[0]->endedAt());
        self::assertNull($campaign->flowKey());
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT flow_key FROM play_campaign WHERE id = ?', [$id->toString()]));
    }

    #[Test]
    public function aMalformedSessionEndFailsToLoad(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [], "endedAt": "yesterday"}]');

        $this->expectExceptionMessageIsOrContains('Stored campaign sessions: "endedAt" must be a time like 2026-10-06T10:00:00.000000+00:00.');

        $this->repository->ofId($id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedScenes(): iterable
    {
        yield 'unknown kind' => ['"kind": "interlude"', 'Stored campaign sessions: "kind" must be one of "scene", "hook".'];
        yield 'non-string Scene Type' => ['"sceneType": 7', 'Stored campaign sessions: "sceneType" must be a string.'];
        yield 'hook kind without a hook' => ['"kind": "hook", "hook": null', 'Stored campaign sessions: "hook" must be set on a hook scene.'];
        yield 'hook name on a scene of play' => ['"kind": "scene", "hook": "worldTurn"', 'Stored campaign sessions: "hook" must be null on a scene of play.'];
        yield 'default title record that is not a boolean' => ['"defaultTitle": "yes"', 'Stored campaign sessions: "defaultTitle" must be a boolean or null.'];
        yield 'cast that is not a list' => ['"cast": "npc-1"', 'Stored campaign sessions: "cast" must be a list.'];
        yield 'cast of an object' => ['"cast": {"first": "npc-1"}', 'Stored campaign sessions: "cast" must be a list.'];
        yield 'cast with a non-string id' => ['"cast": ["npc-1", 7]', 'Stored campaign sessions: "cast" must be a list of NPC id strings.'];
        yield 'cast with a blank id' => ['"cast": ["npc-1", " "]', 'Stored campaign sessions: "cast" must be a list of non-blank NPC ids.'];
        yield 'cast with an NPC twice' => ['"cast": ["npc-1", "npc-3", "npc-1"]', 'Stored campaign sessions: "cast" must be a list of distinct NPC ids (NPC "npc-1" is in the cast twice.).'];
        yield 'characters that are not a list' => ['"characters": "c-1"', 'Stored campaign sessions: "characters" must be a list.'];
        yield 'characters with a non-string id' => ['"characters": ["c-1", 7]', 'Stored campaign sessions: "characters" must be a list of Character id strings.'];
        yield 'characters with a blank id' => ['"characters": ["c-1", " "]', 'Stored campaign sessions: "characters" must be a list of non-blank Character ids.'];
        yield 'characters with a Character twice' => ['"characters": ["c-1", "c-1"]', 'Stored campaign sessions: "characters" must be a list of distinct Character ids (Character "c-1" is in the cast twice.).'];
        yield 'hook name without a kind' => ['"hook": "worldTurn"', 'Stored campaign sessions: "hook" must be null on a scene of play.'];
        yield 'unknown hook' => ['"kind": "hook", "hook": "dawn"', 'Stored campaign sessions: "hook" must be one of "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
    }

    #[Test]
    #[DataProvider('malformedScenes')]
    public function aMalformedStoredSceneFailsToLoad(string $fields, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, \sprintf('[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00", %s}]}]', $fields));

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    #[Test]
    public function aScenesCastRoundTripsInOrderWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithLists($id);
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-10T09:06:00+00:00'));
        $campaign->editCurrentSceneCast([NpcId::fromString('npc-3'), NpcId::fromString('npc-2'), NpcId::fromString('npc-1')]);
        $campaign->startScene('Alone', new \DateTimeImmutable('2026-10-10T09:30:00+00:00'));
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame([['npc-3', 'npc-2', 'npc-1'], []], array_map(static fn (Scene $scene): array => array_map(static fn (NpcId $npc): string => $npc->toString(), $scene->cast()), $loaded?->sessions()[0]->scenes() ?? []));
        $stored = $this->storedList($id->toString(), 'sessions')[0];
        self::assertIsArray($stored);
        self::assertIsArray($stored['scenes']);
        self::assertSame([['npc-3', 'npc-2', 'npc-1'], []], array_column($stored['scenes'], 'cast'));
    }

    #[Test]
    public function aPartyAndTheCharactersInAScenesCastRoundTripInOrderWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithCharacters($id);
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'));
        $campaign->editCurrentSessionParty([CharacterId::fromString('c-2'), CharacterId::fromString('c-1')]);
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-10T09:06:00+00:00'));
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('c-1')]);
        $campaign->endSession(new \DateTimeImmutable('2026-10-10T10:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-17T09:05:00+00:00'));
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertNotNull($loaded);
        self::assertSame([['c-2', 'c-1'], ['c-2', 'c-1']], array_map(static fn (Session $session): array => array_map(static fn (CharacterId $character): string => $character->toString(), $session->party()), $loaded->sessions()));
        self::assertSame(['c-1'], array_map(static fn (CharacterId $character): string => $character->toString(), $loaded->sessions()[0]->scenes()[0]->characters()));
        $stored = $this->storedList($id->toString(), 'sessions');
        self::assertSame([['c-2', 'c-1'], ['c-2', 'c-1']], array_column($stored, 'party'));
        $first = $stored[0];
        self::assertIsArray($first);
        self::assertIsArray($first['scenes']);
        self::assertSame([['c-1']], array_column($first['scenes'], 'characters'));
    }

    #[Test]
    public function aChangeToThePartyIsSaved(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithCharacters($id);
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'));
        $this->repository->add($campaign);
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);

        $campaign->editCurrentSessionParty([CharacterId::fromString('c-1')]);
        $this->repository->save($campaign);
        $this->forgetLoaded();

        self::assertEquals($campaign, $this->repository->ofId($id));
        self::assertSame([['c-1']], array_column($this->storedList($id->toString(), 'sessions'), 'party'));
    }

    #[Test]
    public function aSessionAndSceneStoredBeforePartiesExistedReadWithEmptyPartiesAndCharacters(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00", "kind": "scene", "sceneType": null, "hook": null, "cast": []}], "endedAt": null}]');

        $session = $this->repository->ofId($id)?->sessions()[0];

        self::assertNotNull($session);
        self::assertSame([[], []], [$session->party(), $session->scenes()[0]->characters()]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedParties(): iterable
    {
        yield 'party that is not a list' => ['"c-1"', 'Stored campaign sessions: "party" must be a list.'];
        yield 'party of an object' => ['{"first": "c-1"}', 'Stored campaign sessions: "party" must be a list.'];
        yield 'party with a non-string id' => ['["c-1", 7]', 'Stored campaign sessions: "party" must be a list of Character id strings.'];
        yield 'party with a blank id' => ['["c-1", " "]', 'Stored campaign sessions: "party" must be a list of non-blank Character ids.'];
        yield 'party with a Character twice' => ['["c-1", "c-1"]', 'Stored campaign sessions: "party" must be a list of distinct Character ids (Character "c-1" is in the party twice.).'];
    }

    #[Test]
    #[DataProvider('malformedParties')]
    public function aMalformedStoredPartyFailsToLoad(string $party, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, \sprintf('[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [], "party": %s}]', $party));

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    #[Test]
    public function aPartyOrSceneCastNamingACharacterTheCampaignDoesNotHoldFailsToLoad(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithCharacters($id));
        $session = '{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00", "characters": %s}], "party": %s}';

        $this->storeSessions($id, \sprintf('['.$session.']', '[]', '["c-1", "ghost"]'));
        try {
            $this->repository->ofId($id);
            self::fail('A party naming an unknown Character was read.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('The party of session 1 names Character "ghost", which the campaign does not hold.', $exception->getMessage().$exception->getPrevious()?->getMessage());
        }

        $this->storeSessions($id, \sprintf('['.$session.']', '["c-1", "ghost"]', '["c-1"]'));
        $this->expectExceptionMessageIsOrContains('Scene 1 of session 1 casts Character "ghost", which the campaign does not hold.');

        $this->repository->ofId($id);
    }

    #[Test]
    public function npcsAndThreadsRoundTripWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithLists($id);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame(['Mira', 'Brann', 'Old Tam'], array_map(static fn ($npc): string => $npc->name(), $loaded?->npcs() ?? []));
        $connection = $this->entityManager->getConnection();
        self::assertSame(['npcs' => 'jsonb', 'threads' => 'jsonb'], $connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name IN ('npcs', 'threads') ORDER BY column_name",
        ));
        self::assertEquals(
            [['id' => 'npc-1', 'name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['ally', 'rival'], 'freeTags' => ['Fence'], 'weight' => 3, 'status' => 'open', 'promotedTo' => null]],
            \array_slice($this->storedList($id->toString(), 'npcs'), 0, 1),
        );
    }

    #[Test]
    public function aChangeToAListedItemIsSaved(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithLists($id));
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);

        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $campaign->editThread(ThreadId::fromString('thread-1'), 'The vault job', ['ally'], [], 2, $this->release());
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);
        self::assertEquals($campaign, $loaded);
        self::assertSame(['Brann', 'Mira', 'Old Tam'], array_map(static fn ($npc): string => $npc->name(), $loaded?->npcs() ?? []));
        self::assertSame('The vault job', $loaded?->thread(ThreadId::fromString('thread-1'))?->name());
    }

    #[Test]
    public function factsRoundTripWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithFacts($id);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame(['city', 'prince', 'goal', null, null], array_map(static fn (Fact $fact): ?string => $fact->slot(), $loaded?->facts($this->release()) ?? []));
        self::assertSame(['facts' => 'jsonb'], $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'facts'",
        ));
        self::assertEquals(
            ['id' => 'fact-free', 'slot' => null, 'label' => 'Guild', 'text' => 'Owes a debt', 'subject' => null, 'links' => [['kind' => 'npc', 'id' => 'npc-1'], ['kind' => 'thread', 'id' => 'thread-1'], ['kind' => 'fact', 'id' => 'fact-city']]],
            $this->storedList($id->toString(), 'facts')[3],
        );
        self::assertEquals(
            ['id' => 'fact-prince', 'slot' => 'prince', 'label' => null, 'text' => null, 'subject' => ['kind' => 'npc', 'id' => 'npc-1'], 'links' => []],
            $this->storedList($id->toString(), 'facts')[1],
        );
    }

    #[Test]
    public function aChangeToAFactIsSaved(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithFacts($id));
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);

        $campaign->fillFactSlot(FactId::fromString('ignored'), 'city', FactFill::text('Brightwater'), $this->release());
        $campaign->editFact(FactId::fromString('fact-free'), 'The Guild', null, [FactLink::npc(NpcId::fromString('npc-2'))], $this->release());
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);
        self::assertEquals($campaign, $loaded);
        self::assertSame(['Brightwater', 'The Guild'], [$loaded?->fact(FactId::fromString('fact-city'))?->text(), $loaded?->fact(FactId::fromString('fact-free'))?->label()]);
    }

    #[Test]
    public function aSaveAfterAFactChangeRefusesAStaleCopy(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->forgetLoaded();
        $mine = $this->repository->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->addFreeFact(FactId::fromString('fact-1'), 'Guild', 'Text', [], $this->release());
        $this->campaignsElsewhere()->save($theirs);
        $mine->addFreeFact(FactId::fromString('fact-2'), 'Other', 'Text', [], $this->release());

        $this->expectException(CampaignModifiedConcurrently::class);

        $this->repository->save($mine);
    }

    #[Test]
    public function aRowStoredBeforeFactsExistedReadsWithNoFacts(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', ?, 'Old', 'heist', 1, 'Heist', '2026-10-06 10:00:00+00', '[]', '{}')",
            [self::OWNER],
        );

        $campaign = $this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertSame([], $campaign?->facts($this->release()));
        self::assertSame('[]', $connection->fetchOne('SELECT facts FROM play_campaign'));
    }

    #[Test]
    public function aRowStoredBeforeListsExistedReadsWithNoNpcsOrThreads(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', ?, 'Old', 'heist', 1, 'Heist', '2026-10-06 10:00:00+00', '[]', '{}')",
            [self::OWNER],
        );

        $campaign = $this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertSame([[], []], [$campaign?->npcs(), $campaign?->threads()]);
        self::assertSame(['[]', '[]'], [$connection->fetchOne('SELECT npcs FROM play_campaign'), $connection->fetchOne('SELECT threads FROM play_campaign')]);
    }

    #[Test]
    public function charactersRoundTripWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithCharacters($id);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame(['Vi', 'Mira'], array_map(static fn (Character $character): string => $character->name(), $loaded?->characters() ?? []));
        self::assertSame(['characters' => 'jsonb'], $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'characters'",
        ));
        self::assertEquals(
            [
                ['id' => 'c-1', 'name' => 'Vi', 'tags' => ['ally'], 'freeTags' => ['Fixer'], 'values' => ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 12, 'max' => 40], 'gear' => ['Knife', 'Pistol'], 'wounds' => ['hurt', 'shaken']], 'promotedFrom' => null],
                ['id' => 'c-2', 'name' => 'Mira', 'tags' => [], 'freeTags' => [], 'values' => ['role' => '', 'reflexes' => 4, 'hp' => ['current' => 40, 'max' => 40], 'gear' => [], 'wounds' => []], 'promotedFrom' => null],
            ],
            $this->storedList($id->toString(), 'characters'),
        );
    }

    #[Test]
    public function aReleaseUpgradeRoundTripsWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $at = new \DateTimeImmutable('2026-10-18T10:00:00+00:00');
        $heat = Tracker::counter('heat', 'Heat', null, 0, 10, 2);
        $legwork = new SceneType('legwork', 'Legwork', 'A purpose.', null, [], new StepList(), new StepList(), new StepList());
        $flow = UpgradeFixtures::flow('main', true);
        $slots = [new FactSlot('city', 'City', FactSlotType::Text), new FactSlot('goal', 'Goal', FactSlotType::Text)];
        $pinned = UpgradeFixtures::release(1, [new NumberField('reflexes', 'Reflexes', max: 10), new TrackField('hp', 'Hit points', max: 40), new ListField('gear', 'Gear', maxItems: 3)], ['ally', 'rival'], [$heat], $slots, [$flow], sceneTypes: [$legwork]);
        $target = UpgradeFixtures::release(2, [new NumberField('reflexes', 'Reflexes', max: 5), new TrackField('hp', 'Hit points', max: 10), new ListField('gear', 'Gear', maxItems: 2), new TextField('role', 'Role')], ['ally'], [Tracker::counter('heat', 'Heat', null, 0, 6, 2)], [$slots[1]], [$flow], sceneTypes: [$legwork]);
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [$heat], $flow);
        $campaign->setTrackerValue($heat, 9);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['rival', 'ally'], ['Fixer'], ['reflexes' => 8, 'hp' => ['current' => 40, 'max' => 40], 'gear' => ['a', 'b', 'c']], $pinned);
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['rival'], ['Fence'], 2, $pinned);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', ['rival', 'ally'], [], 1, $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-city'), 'city', FactFill::text('Ashford'), $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-goal'), 'goal', FactFill::text('Rob the vault'), $pinned);
        $campaign->startSession($at, $pinned);
        $this->repository->add($campaign);

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $at);
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame(2, $loaded?->pinnedRelease()->releaseVersion());
        self::assertEquals(['reflexes' => 5, 'hp' => ['current' => 10, 'max' => 10], 'gear' => ['a', 'b'], 'role' => ''], $loaded->character(CharacterId::fromString('c-1'))?->values());
        self::assertSame(6, $loaded->trackerValuesOf($target)['heat']);
        self::assertSame('Ashford', $loaded->fact(FactId::fromString('fact-city'))?->text());
        self::assertNull($loaded->fact(FactId::fromString('fact-city'))->slot());
        self::assertNull($loaded->flowRun()?->scene(), 'The restarted FlowRun is in no scene.');
    }

    #[Test]
    public function aTrackReloadedFromJsonbInAnotherKeyOrderIsUnchangedByAnUpgradeToTheSameBounds(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $pinned = UpgradeFixtures::release(1, [new TrackField('hp', 'Hit points')]);
        $target = UpgradeFixtures::release(2, [new TrackField('hp', 'Hit points', max: 5)]);
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['current' => 4, 'max' => 5]], $pinned);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNotNull($loaded);
        /** @var array{values: array{hp: array<string, int>}} $stored */
        $stored = $this->storedList($id->toString(), 'characters')[0];
        self::assertSame(['max', 'current'], array_keys($stored['values']['hp']), 'JSONB stores the track keys in its own order.');
        self::assertSame([], $loaded->planReleaseUpgrade($pinned, $target)->characters);
    }

    #[Test]
    public function aChangeToACharacterIsSaved(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithCharacters($id));
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);

        $campaign->editCharacter(CharacterId::fromString('c-1'), 'Violet', [], [], ['reflexes' => 9], $this->releaseWithSheet());
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);
        self::assertEquals($campaign, $loaded);
        self::assertSame(9, $loaded?->character(CharacterId::fromString('c-1'))?->values()['reflexes']);
    }

    #[Test]
    public function theMarkedConditionsOfACharacterAreSavedAndReadBackInDeclaredOrder(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithCharacters($id));
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);
        self::assertSame(['hurt', 'shaken'], $campaign->character(CharacterId::fromString('c-1'))?->values()['wounds']);

        $campaign->changeCharacterValue(CharacterId::fromString('c-2'), 'wounds', ['shaken']);
        $campaign->changeCharacterValue(CharacterId::fromString('c-1'), 'wounds', []);
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);
        self::assertEquals($campaign, $loaded);
        self::assertSame([[], ['shaken']], [$loaded?->character(CharacterId::fromString('c-1'))?->values()['wounds'], $loaded?->character(CharacterId::fromString('c-2'))?->values()['wounds']]);
    }

    #[Test]
    public function theStacksOfAnInventoryAreSavedAndReadBackAsTheyWereGiven(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $release = $this->releaseWithInventory();
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['pack' => [['quantity' => 2, 'gear' => 'pistol'], ['slots' => 1, 'name' => ' Rope ', 'quantity' => 1]]], $release);
        $campaign->addCharacter(CharacterId::fromString('c-2'), 'Mira', [], [], [], $release);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame([['gear' => 'pistol', 'quantity' => 2], ['name' => 'Rope', 'quantity' => 1, 'slots' => 1]], $loaded?->character(CharacterId::fromString('c-1'))?->values()['pack']);
        self::assertSame([], $loaded->character(CharacterId::fromString('c-2'))?->values()['pack']);
        /** @var array{values: array{pack: list<array<string, int|string>>}} $stored */
        $stored = $this->storedList($id->toString(), 'characters')[0];
        // JSONB keeps the keys of an object in its own order; the domain reads them back in the canonical one.
        self::assertEquals([['gear' => 'pistol', 'quantity' => 2], ['name' => 'Rope', 'quantity' => 1, 'slots' => 1]], $stored['values']['pack']);

        $loaded->editCharacter(CharacterId::fromString('c-2'), 'Mira', [], [], ['pack' => [['gear' => 'pistol', 'quantity' => 1]]], $release);
        $this->repository->save($loaded);
        $this->forgetLoaded();

        self::assertEquals($loaded, $this->repository->ofId($id));
    }

    #[Test]
    public function aCharacterStoredBeforeInventoriesExistedReadsUnchanged(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $legacy = [['id' => 'c-1', 'name' => 'Vi', 'tags' => ['ally'], 'freeTags' => ['Fixer'], 'values' => ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 12, 'max' => 40], 'gear' => ['Knife', 'Pistol'], 'wounds' => ['shaken']], 'promotedFrom' => null]];
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNotNull($loaded);
        self::assertEquals($legacy[0]['values'], $loaded->character(CharacterId::fromString('c-1'))?->values());
        $this->repository->save($loaded);
        $this->forgetLoaded();
        self::assertEquals($legacy[0]['values'], $this->repository->ofId($id)?->character(CharacterId::fromString('c-1'))?->values());
    }

    #[Test]
    public function aRowStoredBeforeCharactersExistedReadsWithNoCharacters(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', ?, 'Old', 'heist', 1, 'Heist', '2026-10-06 10:00:00+00', '[]', '{}')",
            [self::OWNER],
        );

        $campaign = $this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertSame([], $campaign?->characters());
        self::assertSame('[]', $connection->fetchOne('SELECT characters FROM play_campaign'));
    }

    #[Test]
    public function aPromotedNpcAndItsCharacterRoundTripWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithCharacters($id);
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->releaseWithSheet());
        $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-3'), $this->releaseWithSheet());
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame('c-3', $loaded?->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
        self::assertSame('npc-1', $loaded->character(CharacterId::fromString('c-3'))?->promotedFrom()?->toString());
        self::assertEquals(
            [['id' => 'npc-1', 'name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'closed', 'promotedTo' => 'c-3']],
            $this->storedList($id->toString(), 'npcs'),
        );
    }

    #[Test]
    public function anNpcRowStoredBeforePromotionExistedReadsAsNotPromoted(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $row = ['id' => 'npc-1', 'name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'closed'];
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET npcs = ? WHERE id = ?', [json_encode([$row], \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $npc = $this->repository->ofId($id)?->npc(NpcId::fromString('npc-1'));

        self::assertNotNull($npc);
        self::assertFalse($npc->isPromoted());
        self::assertNull($npc->promotedTo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenPromotions(): iterable
    {
        $npc = static fn (?string $promotedTo): string => json_encode([['id' => 'npc-1', 'name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'closed', 'promotedTo' => $promotedTo]], \JSON_THROW_ON_ERROR);
        $character = static fn (?string $promotedFrom): string => json_encode([['id' => 'c-1', 'name' => 'Mira', 'tags' => [], 'freeTags' => [], 'values' => [], 'promotedFrom' => $promotedFrom]], \JSON_THROW_ON_ERROR);

        yield 'an NPC promoted to a missing Character' => [$npc('c-1'), '[]', 'NPC "npc-1" was promoted to Character "c-1", which does not come from it.'];
        yield 'an NPC promoted to a Character from nowhere' => [$npc('c-1'), $character(null), 'NPC "npc-1" was promoted to Character "c-1", which does not come from it.'];
        yield 'a Character from a missing NPC' => ['[]', $character('npc-1'), 'Character "c-1" comes from NPC "npc-1", which was not promoted to it.'];
        yield 'a Character from an NPC that was not promoted' => [$npc(null), $character('npc-1'), 'Character "c-1" comes from NPC "npc-1", which was not promoted to it.'];
    }

    #[Test]
    #[DataProvider('brokenPromotions')]
    public function aPromotionNamedByOneSideOnlyFailsToLoad(string $npcs, string $characters, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET npcs = ?, characters = ? WHERE id = ?', [$npcs, $characters, $id->toString()]);
        $this->forgetLoaded();

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    #[Test]
    public function aSaveAfterACharacterChangeRefusesAStaleCopy(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->forgetLoaded();
        $mine = $this->repository->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $this->releaseWithSheet());
        $this->campaignsElsewhere()->save($theirs);
        $mine->addCharacter(CharacterId::fromString('c-2'), 'Mira', [], [], [], $this->releaseWithSheet());

        $this->expectException(CampaignModifiedConcurrently::class);

        $this->repository->save($mine);
    }

    #[Test]
    public function aSaveAfterAListChangeRefusesAStaleCopy(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->forgetLoaded();
        $mine = $this->repository->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release());
        $this->campaignsElsewhere()->save($theirs);
        $mine->addThread(ThreadId::fromString('thread-1'), 'The vault job', [], [], 1, $this->release());

        $this->expectException(CampaignModifiedConcurrently::class);

        $this->repository->save($mine);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedLists(): iterable
    {
        $npcRow = static fn (array $fields): array => $fields + ['id' => 'npc-1', 'name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'];
        $threadRow = static fn (array $fields): array => $fields + ['id' => 'thread-1', 'name' => 'Vault', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'];
        $npc = static fn (array $fields): string => json_encode([$npcRow($fields)], \JSON_THROW_ON_ERROR);
        $thread = static fn (array $fields): string => json_encode([$threadRow($fields)], \JSON_THROW_ON_ERROR);

        yield 'npcs not a list' => ['npcs', '{"a": 1}', 'Stored campaign npcs: "npcs" must be a list.'];
        yield 'npc unknown status' => ['npcs', $npc(['status' => 'lost']), 'Stored campaign npcs: "status" must be one of "open", "closed".'];
        yield 'npc missing name' => ['npcs', $npc(['name' => null]), 'Stored campaign npcs: "name" must be a string.'];
        yield 'npc weight not an integer' => ['npcs', $npc(['weight' => '2']), 'Stored campaign npcs: "weight" must be an integer.'];
        yield 'npc tags not strings' => ['npcs', $npc(['tags' => [3]]), 'Stored campaign npcs: "tags" must be a list of strings.'];
        yield 'npc agenda not a string' => ['npcs', $npc(['agenda' => 4]), 'Stored campaign npcs: "agenda" must be a string.'];
        yield 'npc weight out of range' => ['npcs', $npc(['weight' => 4]), 'The weight must be 1 to 3, got 4.'];
        yield 'npc duplicate tag' => ['npcs', $npc(['tags' => ['ally', 'ally']]), 'Tag "ally" is listed twice.'];
        yield 'npc blank name' => ['npcs', $npc(['name' => ' ']), 'The name must not be blank.'];
        yield 'npc blank id' => ['npcs', $npc(['id' => '']), 'An NPC id must not be blank.'];
        yield 'npc promoted to a non-string' => ['npcs', $npc(['status' => 'closed', 'promotedTo' => 4]), 'Stored campaign npcs: "promotedTo" must be a string.'];
        yield 'npc promoted to a blank Character id' => ['npcs', $npc(['status' => 'closed', 'promotedTo' => ' ']), 'A Character id must not be blank.'];
        yield 'npc promoted but open' => ['npcs', $npc(['promotedTo' => 'c-1']), 'A promoted NPC must be closed.'];
        yield 'npc free tags not strings' => ['npcs', $npc(['freeTags' => 'Fence']), 'Stored campaign npcs: "freeTags" must be a list of strings.'];
        yield 'npc duplicate id' => ['npcs', json_encode([$npcRow([]), $npcRow(['name' => 'Brann'])], \JSON_THROW_ON_ERROR), 'Stored campaign npcs: id "npc-1" is listed twice.'];
        yield 'npcs over the limit' => ['npcs', json_encode(array_map(static fn (int $i): array => ['id' => 'npc-'.$i, 'name' => 'NPC '.$i, 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 501)), \JSON_THROW_ON_ERROR), 'Stored campaign npcs: at most 500 NPCs are allowed, got 501.'];
        $characterRow = static fn (array $fields): array => $fields + ['id' => 'c-1', 'name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => ['luck' => 2], 'promotedFrom' => null];
        $character = static fn (array $fields): string => json_encode([$characterRow($fields)], \JSON_THROW_ON_ERROR);

        yield 'characters not a list' => ['characters', '{"a": 1}', 'Stored campaign characters: "characters" must be a list.'];
        yield 'character missing id' => ['characters', $character(['id' => null]), 'Stored campaign characters: "id" must be a string.'];
        yield 'character blank id' => ['characters', $character(['id' => ' ']), 'A Character id must not be blank.'];
        yield 'character blank name' => ['characters', $character(['name' => ' ']), 'The name must not be blank.'];
        yield 'character tags not strings' => ['characters', $character(['tags' => [3]]), 'Stored campaign characters: "tags" must be a list of strings.'];
        yield 'character duplicate tag' => ['characters', $character(['tags' => ['ally', 'ally']]), 'Tag "ally" is listed twice.'];
        yield 'character free tags not strings' => ['characters', $character(['freeTags' => 'Fixer']), 'Stored campaign characters: "freeTags" must be a list of strings.'];
        yield 'character values not an object' => ['characters', $character(['values' => 'many']), 'Stored campaign characters: "values" must be an object.'];
        yield 'character value of no field type' => ['characters', $character(['values' => ['luck' => 1.5]]), 'Field "luck" holds a value of no field type.'];
        yield 'character track above its max' => ['characters', $character(['values' => ['hp' => ['current' => 5, 'max' => 3]]]), 'The current of field "hp" must be 0 to 3, got 5.'];
        yield 'character inventory with a quantity of zero' => ['characters', $character(['values' => ['pack' => [['gear' => 'pistol', 'quantity' => 0]]]]), 'The quantity of a stack of field "pack" must be an integer from 1 to 9999.'];
        yield 'character promoted from a blank NPC id' => ['characters', $character(['promotedFrom' => ' ']), 'An NPC id must not be blank.'];
        yield 'character promoted from a non-string' => ['characters', $character(['promotedFrom' => 4]), 'Stored campaign characters: "promotedFrom" must be a string.'];
        yield 'character duplicate id' => ['characters', json_encode([$characterRow([]), $characterRow(['name' => 'Mira'])], \JSON_THROW_ON_ERROR), 'Stored campaign characters: id "c-1" is listed twice.'];
        yield 'characters over the limit' => ['characters', json_encode(array_map(static fn (int $i): array => ['id' => 'c-'.$i] + $characterRow([]), range(1, 101)), \JSON_THROW_ON_ERROR), 'Stored campaign characters: at most 100 Characters are allowed, got 101.'];
        yield 'threads not a list' => ['threads', '{"a": 1}', 'Stored campaign threads: "threads" must be a list.'];
        yield 'thread unknown status' => ['threads', $thread(['status' => 'done']), 'Stored campaign threads: "status" must be one of "open", "closed".'];
        yield 'thread free tag duplicate' => ['threads', $thread(['freeTags' => ['Fence', 'fence']]), 'Free tag "fence" is listed twice.'];
        yield 'thread missing name' => ['threads', $thread(['name' => null]), 'Stored campaign threads: "name" must be a string.'];
        yield 'thread weight not an integer' => ['threads', $thread(['weight' => '2']), 'Stored campaign threads: "weight" must be an integer.'];
        yield 'thread tags not strings' => ['threads', $thread(['tags' => [3]]), 'Stored campaign threads: "tags" must be a list of strings.'];
        yield 'thread free tags not strings' => ['threads', $thread(['freeTags' => 'Heist']), 'Stored campaign threads: "freeTags" must be a list of strings.'];
        yield 'thread weight out of range' => ['threads', $thread(['weight' => 0]), 'The weight must be 1 to 3, got 0.'];
        yield 'thread duplicate tag' => ['threads', $thread(['tags' => ['ally', 'ally']]), 'Tag "ally" is listed twice.'];
        yield 'thread blank name' => ['threads', $thread(['name' => ' ']), 'The name must not be blank.'];
        yield 'thread blank id' => ['threads', $thread(['id' => '']), 'A Thread id must not be blank.'];
        yield 'thread duplicate id' => ['threads', json_encode([$threadRow([]), $threadRow(['name' => 'Debts'])], \JSON_THROW_ON_ERROR), 'Stored campaign threads: id "thread-1" is listed twice.'];
        yield 'threads over the limit' => ['threads', json_encode(array_map(static fn (int $i): array => ['id' => 'thread-'.$i, 'name' => 'Thread '.$i, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 501)), \JSON_THROW_ON_ERROR), 'Stored campaign threads: at most 500 Threads are allowed, got 501.'];
        $factRow = static fn (array $fields): array => $fields + ['id' => 'fact-1', 'slot' => null, 'label' => 'Guild', 'text' => 'Owes a debt', 'subject' => null, 'links' => []];
        $fact = static fn (array $fields): string => json_encode([$factRow($fields)], \JSON_THROW_ON_ERROR);

        yield 'facts not a list' => ['facts', '{"a": 1}', 'Stored campaign facts: "facts" must be a list.'];
        yield 'fact missing id' => ['facts', $fact(['id' => null]), 'Stored campaign facts: "id" must be a string.'];
        yield 'fact blank id' => ['facts', $fact(['id' => ' ']), 'A fact id must not be blank.'];
        yield 'fact label not a string' => ['facts', $fact(['label' => 4]), 'Stored campaign facts: "label" must be a string.'];
        yield 'fact text not a string' => ['facts', $fact(['text' => ['a']]), 'Stored campaign facts: "text" must be a string.'];
        yield 'fact slot not a string' => ['facts', $fact(['slot' => 3]), 'Stored campaign facts: "slot" must be a string.'];
        yield 'free fact without a label' => ['facts', $fact(['label' => null]), 'The label must not be blank.'];
        yield 'free fact with a long text' => ['facts', $fact(['text' => str_repeat('a', 2001)]), 'The text must be at most 2000 characters, got 2001.'];
        yield 'free fact with a subject' => ['facts', $fact(['subject' => ['kind' => 'npc', 'id' => 'npc-1']]), 'A free fact has no subject.'];
        yield 'slot fact with a label' => ['facts', $fact(['slot' => 'city']), 'A slot fact has no label; the slot has one.'];
        yield 'slot fact with a text and a subject' => ['facts', $fact(['slot' => 'city', 'label' => null, 'subject' => ['kind' => 'npc', 'id' => 'npc-1']]), 'A slot fact holds a text or a subject, not both and not neither.'];
        yield 'slot fact with a fact as subject' => ['facts', $fact(['slot' => 'city', 'label' => null, 'text' => null, 'subject' => ['kind' => 'fact', 'id' => 'fact-2']]), 'A subject is an NPC or a Thread.'];
        yield 'subject with an unknown kind' => ['facts', $fact(['slot' => 'city', 'label' => null, 'text' => null, 'subject' => ['kind' => 'place', 'id' => 'p']]), 'Stored campaign facts: "subject" must be a link with kind "npc", "thread" or "fact".'];
        yield 'subject without an id' => ['facts', $fact(['slot' => 'city', 'label' => null, 'text' => null, 'subject' => ['kind' => 'npc']]), 'Stored campaign facts: "id" must be a string.'];
        yield 'links not a list' => ['facts', $fact(['links' => 'npc-1']), 'Stored campaign facts: "links" must be a list of links.'];
        yield 'link with an unknown kind' => ['facts', $fact(['links' => [['kind' => 'place', 'id' => 'p']]]), 'Stored campaign facts: "links" must be a link with kind "npc", "thread" or "fact".'];
        yield 'link without an id' => ['facts', $fact(['links' => [['kind' => 'npc', 'id' => 4]]]), 'Stored campaign facts: "id" must be a string.'];
        yield 'link listed twice' => ['facts', $fact(['links' => [['kind' => 'npc', 'id' => 'npc-1'], ['kind' => 'npc', 'id' => 'npc-1']]]), 'The npc "npc-1" is linked twice.'];
        yield 'link to itself' => ['facts', $fact(['links' => [['kind' => 'fact', 'id' => 'fact-1']]]), 'A fact cannot link to itself.'];
        yield 'too many links' => ['facts', $fact(['links' => array_map(static fn (int $i): array => ['kind' => 'fact', 'id' => 'other-'.$i], range(1, 21))]), 'At most 20 links are allowed, got 21.'];
        yield 'fact duplicate id' => ['facts', json_encode([$factRow([]), $factRow(['label' => 'Other'])], \JSON_THROW_ON_ERROR), 'Stored campaign facts: id "fact-1" is listed twice.'];
        yield 'two facts for one slot' => ['facts', json_encode([$factRow(['slot' => 'city', 'label' => null]), $factRow(['id' => 'fact-2', 'slot' => 'city', 'label' => null])], \JSON_THROW_ON_ERROR), 'Stored campaign facts: slot "city" holds two facts.'];
        yield 'facts over the limit' => ['facts', json_encode(array_map(static fn (int $i): array => ['id' => 'fact-'.$i] + $factRow([]), range(1, 501)), \JSON_THROW_ON_ERROR), 'Stored campaign facts: at most 500 facts are allowed, got 501.'];
        yield 'fact linking to an NPC the campaign lacks' => ['facts', $fact(['links' => [['kind' => 'npc', 'id' => 'ghost']]]), 'NPC "ghost" not found.'];
        yield 'fact linking to a Thread the campaign lacks' => ['facts', $fact(['links' => [['kind' => 'thread', 'id' => 'ghost']]]), 'Thread "ghost" not found.'];
        yield 'fact linking to a fact the campaign lacks' => ['facts', $fact(['links' => [['kind' => 'fact', 'id' => 'ghost']]]), 'Fact "ghost" not found.'];
        yield 'slot fact whose subject the campaign lacks' => ['facts', $fact(['slot' => 'prince', 'label' => null, 'text' => null, 'subject' => ['kind' => 'npc', 'id' => 'ghost']]), 'NPC "ghost" not found.'];
        yield 'thread missing id' => ['threads', $thread(['id' => null]), 'Stored campaign threads: "id" must be a string.'];
    }

    #[Test]
    #[DataProvider('malformedLists')]
    public function aMalformedStoredListFailsToLoad(string $column, string $stored, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->entityManager->getConnection()->executeStatement(\sprintf('UPDATE play_campaign SET %s = ? WHERE id = ?', $column), [$stored, $id->toString()]);
        $this->forgetLoaded();

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    /**
     * @return iterable<string, array{CampaignNpcsType|CampaignThreadsType|CampaignFactsType|CampaignCharactersType, mixed}>
     */
    public static function valuesThatAreNotLists(): iterable
    {
        yield 'npcs: a string' => [new CampaignNpcsType(), 'npc-1'];
        yield 'npcs: a stored-shape array item' => [new CampaignNpcsType(), [['id' => 'npc-1']]];
        yield 'npcs: a thread among them' => [new CampaignNpcsType(), [Thread::of(ThreadId::fromString('thread-1'), 'Vault', [], [], 1)]];
        yield 'characters: a string' => [new CampaignCharactersType(), 'c-1'];
        yield 'characters: a stored-shape array item' => [new CampaignCharactersType(), [['id' => 'c-1']]];
        yield 'characters: an npc among them' => [new CampaignCharactersType(), [Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1)]];
        yield 'facts: a string' => [new CampaignFactsType(), 'fact-1'];
        yield 'facts: a stored-shape array item' => [new CampaignFactsType(), [['id' => 'fact-1']]];
        yield 'facts: an npc among them' => [new CampaignFactsType(), [Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1)]];
        yield 'threads: a string' => [new CampaignThreadsType(), 'thread-1'];
        yield 'threads: a stored-shape array item' => [new CampaignThreadsType(), [['id' => 'thread-1']]];
        yield 'threads: an npc among them' => [new CampaignThreadsType(), [Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1)]];
    }

    #[Test]
    #[DataProvider('valuesThatAreNotLists')]
    public function aValueThatIsNotAListOfItemsIsRefusedBeforeItIsWritten(CampaignNpcsType|CampaignThreadsType|CampaignFactsType|CampaignCharactersType $type, mixed $value): void
    {
        $this->expectException(InvalidType::class);

        $type->convertToDatabaseValue($value, $this->entityManager->getConnection()->getDatabasePlatform());
    }

    private function releaseWithSheet(): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival')], sheet: new SheetTemplate([
            new TextField('role', 'Role'),
            new NumberField('reflexes', 'Reflexes', min: 1, max: 10, default: 4),
            new TrackField('hp', 'Hit points', max: 40),
            new ListField('gear', 'Gear'),
            new ConditionsField('wounds', 'Wounds', options: [new ConditionOption('hurt', 'Hurt'), new ConditionOption('shaken', 'Shaken')]),
        ]));
    }

    private function releaseWithInventory(): GameSystemSnapshot
    {
        $gear = [new GearEntry('pistol', 'Pistol', 50, 1, 'Gear'), new GearEntry('optic', 'Optic', 500, 0, 'Chrome')];

        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], sheet: new SheetTemplate([
            new InventoryField('pack', 'Pack', capacity: 5, categories: ['Gear'], catalog: $gear),
        ]), gear: $gear);
    }

    /**
     * A campaign with two Characters: one with every field type set, one with every default.
     */
    private function campaignWithCharacters(CampaignId $id): Campaign
    {
        $release = $this->releaseWithSheet();
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], ['Fixer'], ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 12], 'gear' => ['Knife', 'Pistol'], 'wounds' => ['shaken', 'hurt']], $release);
        $campaign->addCharacter(CharacterId::fromString('c-2'), 'Mira', [], [], [], $release);

        return $campaign;
    }

    private function release(): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text), new FactSlot('prince', 'The Prince', FactSlotType::Npc), new FactSlot('goal', 'Goal', FactSlotType::Thread)], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival', 'Someone in the way')]);
    }

    /**
     * A campaign with three NPCs (the second closed) and two Threads (the last closed): agendas null and set, tags, free tags and weights vary.
     */
    private function campaignWithLists(CampaignId $id): Campaign
    {
        $release = $this->release();
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally', 'rival'], ['Fence'], 3, $release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Old Tam', null, [], [], 1, $release);
        $campaign->addNpc(NpcId::fromString('npc-3'), 'Brann', 'Keeps the keys', ['rival'], ['Smith', 'Night shift'], 2, $release);
        $campaign->closeNpc(NpcId::fromString('npc-2'));
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', ['ally'], ['Heist'], 2, $release);
        $campaign->addThread(ThreadId::fromString('thread-2'), 'Old debts', [], [], 1, $release);
        $campaign->closeThread(ThreadId::fromString('thread-2'));

        return $campaign;
    }

    /**
     * A campaign with two NPCs and a Thread and five facts: a slot fact of each type (the NPC
     * closed, the Thread created by name) and two free facts, one linking to an NPC, a Thread and a
     * slot fact; facts are stored in creation order, not in release slot order.
     */
    private function campaignWithFacts(CampaignId $id): Campaign
    {
        $release = $this->release();
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Old Tam', null, [], [], 1, $release);
        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', [], [], 1, $release);
        $campaign->fillFactSlot(FactId::fromString('fact-goal'), 'goal', FactFill::newThread(ThreadId::fromString('thread-2'), 'Find the heir'), $release);
        $campaign->fillFactSlot(FactId::fromString('fact-prince'), 'prince', FactFill::existingNpc(NpcId::fromString('npc-1')), $release);
        $campaign->fillFactSlot(FactId::fromString('fact-city'), 'city', FactFill::text('Ashford'), $release);
        $campaign->addFreeFact(FactId::fromString('fact-free'), 'Guild', 'Owes a debt', [FactLink::npc(NpcId::fromString('npc-1')), FactLink::thread(ThreadId::fromString('thread-1')), FactLink::fact(FactId::fromString('fact-city'))], $release);
        $campaign->addFreeFact(FactId::fromString('fact-plain'), 'Weather', 'Rain', [], $release);
        $campaign->editFact(FactId::fromString('fact-goal'), null, null, [FactLink::fact(FactId::fromString('fact-free'))], $release);

        return $campaign;
    }

    /**
     * @return list<mixed> a stored list column, decoded, in stored (creation) order
     */
    private function storedList(string $id, string $column): array
    {
        $stored = $this->entityManager->getConnection()->fetchOne(\sprintf('SELECT %s FROM play_campaign WHERE id = ?', $column), [$id]);
        self::assertIsString($stored);
        $decoded = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    private function storeSessions(CampaignId $id, string $sessions): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET sessions = ? WHERE id = ?', [$sessions, $id->toString()]);
        $this->forgetLoaded();
    }

    #[Test]
    public function everySaveBumpsTheVersionColumn(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->repository->add($campaign);
        self::assertSame(1, $campaign->version());

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->repository->save($campaign);
        self::assertSame(2, $campaign->version());

        $this->forgetLoaded();
        self::assertSame(2, $this->repository->ofId($id)?->version());
        self::assertSame(2, $this->entityManager->getConnection()->fetchOne('SELECT version FROM play_campaign WHERE id = ?', [$id->toString()]));
    }

    #[Test]
    public function savingACampaignThatWasNeverAddedFails(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->save($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
    }

    #[Test]
    public function aCampaignAddedConcurrentlyWithTheSameIdIsRejectedByThePrimaryKey(): void
    {
        // Another request inserts the same id after this one looked it up, right before it flushes.
        $connection = $this->entityManager->getConnection();
        $concurrentInsert = new readonly class($connection) {
            public function __construct(private \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement(
                    "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5002', 'Theirs', 'free-journal', 1, 'Free journal', '2026-10-06 10:00:00+00', '[]', '{}')",
                );
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentInsert);

        try {
            $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
            self::fail('The concurrent duplicate was not rejected.');
        } catch (CampaignAlreadyExists $exception) {
            self::assertSame('A campaign with id "01890a5d-ac96-774b-bcce-b302099a8057" already exists.', $exception->getMessage());
        } finally {
            $this->entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentInsert);
        }
    }

    /**
     * Documents why the port asks callers to save every change (CampaignRepository::save): Doctrine
     * flushes its whole unit of work, so another write keeps an unsaved change of a loaded campaign.
     */
    #[Test]
    public function anotherWriteAlsoKeepsAnUnsavedChangeOfALoadedCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->repository->ofId($id)?->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));

        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-07'));
        $this->forgetLoaded();

        self::assertSame(1, $this->repository->ofId($id)?->currentSession()?->number());
    }

    #[Test]
    public function theCreationTimeKeepsItsMicroseconds(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06 10:00:00.123456');
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNotNull($loaded);
        self::assertSame('2026-10-06T10:00:00.123456+00:00', $loaded->createdAt()->format('Y-m-d\\TH:i:s.uP'));
        self::assertEquals($campaign, $loaded);
        // The schema tool cannot see a column's precision (doctrine.yaml mapping_types): check it here.
        self::assertSame(6, $this->entityManager->getConnection()->fetchOne(
            "SELECT datetime_precision FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'created_at'",
        ));
    }
}
