<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Play\Application\AddCharacter;
use App\Play\Application\AddCharacterHandler;
use App\Play\Application\AddFact;
use App\Play\Application\AddFactHandler;
use App\Play\Application\AddNpc;
use App\Play\Application\AddNpcHandler;
use App\Play\Application\AddThread;
use App\Play\Application\AddThreadHandler;
use App\Play\Application\CampaignJournal;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CampaignSummaryView;
use App\Play\Application\CampaignView;
use App\Play\Application\CastMemberView;
use App\Play\Application\CharacterView;
use App\Play\Application\CloseNpc;
use App\Play\Application\CloseNpcHandler;
use App\Play\Application\CloseThread;
use App\Play\Application\CloseThreadHandler;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\CreateCampaign;
use App\Play\Application\CreateCampaignHandler;
use App\Play\Application\EditCharacter;
use App\Play\Application\EditCharacterHandler;
use App\Play\Application\EditCurrentSceneCast;
use App\Play\Application\EditCurrentSceneCastHandler;
use App\Play\Application\EditFact;
use App\Play\Application\EditFactHandler;
use App\Play\Application\EditNpc;
use App\Play\Application\EditNpcHandler;
use App\Play\Application\EditThread;
use App\Play\Application\EditThreadHandler;
use App\Play\Application\EndFlowScene;
use App\Play\Application\EndFlowSceneHandler;
use App\Play\Application\EndSession;
use App\Play\Application\EndSessionHandler;
use App\Play\Application\FactIdGenerator;
use App\Play\Application\FactLinkView;
use App\Play\Application\FactView;
use App\Play\Application\FillFactSlot;
use App\Play\Application\FillFactSlotHandler;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\GetJournal;
use App\Play\Application\GetJournalHandler;
use App\Play\Application\JournalEntryView;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\ListMyCampaignsHandler;
use App\Play\Application\NpcIdGenerator;
use App\Play\Application\NpcView;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PauseGuidanceHandler;
use App\Play\Application\PickSceneType;
use App\Play\Application\PickSceneTypeHandler;
use App\Play\Application\PreviewReleaseUpgrade;
use App\Play\Application\PreviewReleaseUpgradeHandler;
use App\Play\Application\PromoteNpc;
use App\Play\Application\PromoteNpcHandler;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordLikelihoodAnswerHandler;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordNoteHandler;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordOracleTableResultHandler;
use App\Play\Application\RecordRoll;
use App\Play\Application\RecordRollHandler;
use App\Play\Application\ReleaseUpgradePreview;
use App\Play\Application\ReopenNpc;
use App\Play\Application\ReopenNpcHandler;
use App\Play\Application\ReopenThread;
use App\Play\Application\ReopenThreadHandler;
use App\Play\Application\ResumeGuidance;
use App\Play\Application\ResumeGuidanceHandler;
use App\Play\Application\SceneView;
use App\Play\Application\SetTrackerValue;
use App\Play\Application\SetTrackerValueHandler;
use App\Play\Application\SheetFieldView;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\SkipFlowStepHandler;
use App\Play\Application\StartScene;
use App\Play\Application\StartSceneHandler;
use App\Play\Application\StartSession;
use App\Play\Application\StartSessionHandler;
use App\Play\Application\SwitchSceneType;
use App\Play\Application\SwitchSceneTypeHandler;
use App\Play\Application\ThreadIdGenerator;
use App\Play\Application\ThreadView;
use App\Play\Application\TrackerView;
use App\Play\Application\UpgradeCampaignRelease;
use App\Play\Application\UpgradeCampaignReleaseHandler;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\InvalidSceneCast;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\SessionAlreadyUnderWay;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\FieldChange;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradeChanged;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\GuidedReleases;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\SequentialCampaignIdGenerator;
use App\Tests\Support\Play\SequentialJournalEntryIdGenerator;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Playing campaigns, on in-memory fakes (no kernel). "I" is one solo player, "another player" a
 * second one. Dice are scripted per step ("the dice show …"); the clock never moves, so journal
 * entries keep their recording order through their sequential ids.
 */
final class PlayContext implements Context
{
    private const string ME = 'user-me';
    private const string ANOTHER_PLAYER = 'user-another';

    private readonly InMemoryPublishedGameSystemReleases $releases;
    private readonly SequentialCampaignIdGenerator $ids;
    private readonly CreateCampaignHandler $createCampaign;
    private readonly StartSessionHandler $startSession;
    private readonly EndSessionHandler $endSession;
    private readonly StartSceneHandler $startScene;
    private readonly SwitchSceneTypeHandler $switchSceneType;
    private readonly SetTrackerValueHandler $setTrackerValue;
    private readonly AddNpcHandler $addNpc;
    private readonly EditNpcHandler $editNpc;
    private readonly AddCharacterHandler $addCharacter;
    private readonly EditCharacterHandler $editCharacter;
    private readonly PromoteNpcHandler $promoteNpc;
    private readonly CloseNpcHandler $closeNpc;
    private readonly ReopenNpcHandler $reopenNpc;
    private readonly EditCurrentSceneCastHandler $editCurrentSceneCast;
    private readonly AddFactHandler $addFact;
    private readonly EditFactHandler $editFact;
    private readonly FillFactSlotHandler $fillFactSlot;
    private readonly AddThreadHandler $addThread;
    private readonly EditThreadHandler $editThread;
    private readonly CloseThreadHandler $closeThread;
    private readonly ReopenThreadHandler $reopenThread;
    private readonly ListMyCampaignsHandler $listMyCampaigns;
    private readonly GetCampaignHandler $getCampaign;
    private readonly SequentialJournalEntryIdGenerator $entryIds;
    private readonly CampaignJournal $journal;
    private readonly RecordNoteHandler $recordNote;
    private readonly GetJournalHandler $getJournal;
    private readonly CompleteFlowStepHandler $completeFlowStep;
    private readonly SkipFlowStepHandler $skipFlowStep;
    private readonly PickSceneTypeHandler $pickSceneType;
    private readonly EndFlowSceneHandler $endFlowScene;
    private readonly PauseGuidanceHandler $pauseGuidance;
    private readonly ResumeGuidanceHandler $resumeGuidance;
    private readonly PreviewReleaseUpgradeHandler $previewReleaseUpgrade;
    private readonly UpgradeCampaignReleaseHandler $upgradeCampaignRelease;

    private ?string $campaignId = null;
    private int $npcCount = 0;
    private int $characterCount = 0;
    private int $threadCount = 0;
    private int $factCount = 0;
    private ?\Throwable $failure = null;
    private ?ReleaseUpgradePreview $preview = null;

    public function __construct()
    {
        $campaigns = new InMemoryCampaignRepository();
        $owned = new OwnedCampaigns($campaigns);
        $clock = new FixedClock();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->ids = new SequentialCampaignIdGenerator();
        $this->createCampaign = new CreateCampaignHandler($campaigns, $this->releases, $clock);
        $this->startSession = new StartSessionHandler($owned, $campaigns, $this->releases, $clock);
        $this->endSession = new EndSessionHandler($owned, $campaigns, $this->releases, $clock);
        $this->startScene = new StartSceneHandler($owned, $campaigns, $this->releases, $clock);
        $this->switchSceneType = new SwitchSceneTypeHandler($owned, $campaigns, $this->releases, $clock);
        $this->setTrackerValue = new SetTrackerValueHandler($owned, $campaigns, $this->releases, $clock);
        $this->addNpc = new AddNpcHandler($owned, $campaigns, $this->releases);
        $this->editNpc = new EditNpcHandler($owned, $campaigns, $this->releases);
        $this->addCharacter = new AddCharacterHandler($owned, $campaigns, $this->releases);
        $this->editCharacter = new EditCharacterHandler($owned, $campaigns, $this->releases);
        $this->promoteNpc = new PromoteNpcHandler($owned, $campaigns, $this->releases);
        $this->closeNpc = new CloseNpcHandler($owned, $campaigns);
        $this->reopenNpc = new ReopenNpcHandler($owned, $campaigns);
        $this->editCurrentSceneCast = new EditCurrentSceneCastHandler($owned, $campaigns);
        $this->addFact = new AddFactHandler($owned, $campaigns, $this->releases);
        $this->editFact = new EditFactHandler($owned, $campaigns, $this->releases);
        $this->fillFactSlot = new FillFactSlotHandler(
            $owned,
            $campaigns,
            $this->releases,
            new readonly class(fn (): int => ++$this->factCount) implements FactIdGenerator {
                /**
                 * @param \Closure(): int $next
                 */
                public function __construct(private \Closure $next)
                {
                }

                public function generate(): FactId
                {
                    return FactId::fromString('fact-'.($this->next)());
                }
            },
            new readonly class(fn (): int => ++$this->npcCount) implements NpcIdGenerator {
                /**
                 * @param \Closure(): int $next
                 */
                public function __construct(private \Closure $next)
                {
                }

                public function generate(): NpcId
                {
                    return NpcId::fromString('npc-'.($this->next)());
                }
            },
            new readonly class(fn (): int => ++$this->threadCount) implements ThreadIdGenerator {
                /**
                 * @param \Closure(): int $next
                 */
                public function __construct(private \Closure $next)
                {
                }

                public function generate(): ThreadId
                {
                    return ThreadId::fromString('thread-'.($this->next)());
                }
            },
        );
        $this->addThread = new AddThreadHandler($owned, $campaigns, $this->releases);
        $this->editThread = new EditThreadHandler($owned, $campaigns, $this->releases);
        $this->closeThread = new CloseThreadHandler($owned, $campaigns);
        $this->reopenThread = new ReopenThreadHandler($owned, $campaigns);
        $this->listMyCampaigns = new ListMyCampaignsHandler($campaigns);
        $this->getCampaign = new GetCampaignHandler($owned, $this->releases);
        $entries = new InMemoryJournalEntryRepository();
        $this->entryIds = new SequentialJournalEntryIdGenerator();
        $this->journal = new CampaignJournal($owned, $entries, $this->releases, $clock);
        $this->recordNote = new RecordNoteHandler($this->journal);
        $this->getJournal = new GetJournalHandler($owned, $entries);
        // Prompt steps only: the dice are never rolled.
        $this->completeFlowStep = new CompleteFlowStepHandler($this->journal, $campaigns, new ScriptedRandomNumberGenerator(), $clock, $this->entryIds);
        $this->skipFlowStep = new SkipFlowStepHandler($owned, $campaigns, $this->releases, $clock);
        $this->pickSceneType = new PickSceneTypeHandler($owned, $campaigns, $this->releases, $clock);
        $this->endFlowScene = new EndFlowSceneHandler($owned, $campaigns, $this->releases, $clock);
        $this->pauseGuidance = new PauseGuidanceHandler($owned, $campaigns, $clock);
        $this->resumeGuidance = new ResumeGuidanceHandler($owned, $campaigns, $this->releases, $clock);
        $this->previewReleaseUpgrade = new PreviewReleaseUpgradeHandler($owned, $this->releases);
        $this->upgradeCampaignRelease = new UpgradeCampaignReleaseHandler($owned, $campaigns, $this->releases, $clock);
    }

    // Behat matches steps by text whatever the keyword, so one attribute serves Given and When.
    #[Given('release version :version of the GameSystem :key named :name is published')]
    public function aReleaseIsPublished(int $version, string $key, string $name): void
    {
        $this->releases->add(Snapshots::bare($key, $name, $version));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with oracles')]
    public function aReleaseWithOraclesIsPublished(int $version, string $key, string $name): void
    {
        // Oracle tables "weather" (1d6: 1-4 Clear, 5-6 Storm → "storm-kind": Rain, Hail) and the
        // likelihood oracle "fate" (d100, "unlikely" 35, chaos 1-9, neutral 5, 5 per point).
        $this->releases->add(Snapshots::withOracles($key, $name, $version));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with trackers')]
    public function aReleaseWithTrackersIsPublished(int $version, string $key, string $name): void
    {
        // Trackers "alarm" (clock of 6), "heat" (-5..5 from -5: Cold up to -1, Warm up to 2, Hot)
        // and "chaos" (1..9 from 5).
        $this->releases->add(Snapshots::withTrackers($key, $name, $version));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with Tags')]
    public function aReleaseWithTagsIsPublished(int $version, string $key, string $name): void
    {
        // Tags "ally" (Ally) and "rival" (Rival, with a hint).
        $this->releases->add(Snapshots::withTags($key, $name, $version));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with Tags and Fact slots')]
    public function aReleaseWithTagsAndFactSlotsIsPublished(int $version, string $key, string $name): void
    {
        // Tags "ally" and "rival"; Fact slots "city" (text) and "prince" (npc).
        $this->releases->add(new GameSystemSnapshot($key, $name, $version, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text), new FactSlot('prince', 'Prince', FactSlotType::Npc)], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival', 'Someone in the way')]));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with Scene Types')]
    public function aReleaseWithSceneTypesIsPublished(int $version, string $key, string $name): void
    {
        // Scene Types "legwork" (Legwork) and "firefight" (Firefight).
        $this->releases->add(Snapshots::withSceneTypes($key, $name, $version));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with Flows')]
    public function aReleaseWithFlowsIsPublished(int $version, string $key, string $name): void
    {
        // Scene Types "legwork" and "firefight"; Flows "the-heist" (the default) and "one-shot".
        $this->releases->add(Snapshots::withFlows($key, $name, $version));
    }

    #[Given('I created the campaign :name with the GameSystem :key playing the Flow :flow')]
    #[When('I create the campaign :name with the GameSystem :key playing the Flow :flow')]
    public function iCreateTheCampaignPlayingTheFlow(string $name, string $key, string $flow): void
    {
        $this->createCampaign($name, $key, $flow);
        Assert::assertNull($this->failure, $this->failure?->getMessage() ?? '');
    }

    #[Given('I created the campaign :name with the GameSystem :key')]
    #[When('I create the campaign :name with the GameSystem :key')]
    public function iCreateTheCampaign(string $name, string $key): void
    {
        $this->createCampaign($name, $key);
        Assert::assertNull($this->failure, $this->failure?->getMessage() ?? '');
    }

    #[When('I try to create the campaign :name with the GameSystem :key')]
    public function iTryToCreateTheCampaign(string $name, string $key): void
    {
        $this->createCampaign($name, $key);
    }

    #[Given('I started a session')]
    #[When('I start a session')]
    public function iStartASession(): void
    {
        ($this->startSession)(new StartSession($this->campaignId(), self::ME));
    }

    #[When('I try to start a session')]
    public function iTryToStartASession(): void
    {
        $this->attempt(fn () => ($this->startSession)(new StartSession($this->campaignId(), self::ME)));
    }

    #[When('I end the session')]
    public function iEndTheSession(): void
    {
        ($this->endSession)(new EndSession($this->campaignId(), self::ME));
    }

    #[Given('I started the scene :title')]
    #[When('I start the scene :title')]
    public function iStartTheScene(string $title): void
    {
        ($this->startScene)(new StartScene($this->campaignId(), self::ME, $title));
    }

    #[When('I start a scene of the Scene Type :sceneType')]
    public function iStartASceneOfTheSceneType(string $sceneType): void
    {
        ($this->startScene)(new StartScene($this->campaignId(), self::ME, null, $sceneType));
    }

    #[When('I start the scene :title of the Scene Type :sceneType')]
    public function iStartTheSceneOfTheSceneType(string $title, string $sceneType): void
    {
        ($this->startScene)(new StartScene($this->campaignId(), self::ME, $title, $sceneType));
    }

    #[When('I switch the current scene to the Scene Type :sceneType')]
    public function iSwitchTheCurrentScene(string $sceneType): void
    {
        ($this->switchSceneType)(new SwitchSceneType($this->campaignId(), self::ME, $sceneType));
    }

    #[When('I try to start the scene :title')]
    public function iTryToStartTheScene(string $title): void
    {
        $this->attempt(fn () => ($this->startScene)(new StartScene($this->campaignId(), self::ME, $title)));
    }

    #[When('another player looks for my campaign')]
    public function anotherPlayerLooksForMyCampaign(): void
    {
        $this->attempt(fn (): CampaignView => ($this->getCampaign)(new GetCampaign($this->campaignId(), self::ANOTHER_PLAYER)));
    }

    #[When('another player tries to start a session in my campaign')]
    public function anotherPlayerTriesToStartASession(): void
    {
        $this->attempt(fn () => ($this->startSession)(new StartSession($this->campaignId(), self::ANOTHER_PLAYER)));
    }

    #[When('I set the Tracker :key to :value')]
    public function iSetTheTracker(string $key, int $value): void
    {
        ($this->setTrackerValue)(new SetTrackerValue($this->campaignId(), self::ME, $key, $value));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with a character sheet')]
    public function aReleaseWithACharacterSheetIsPublished(int $version, string $key, string $name): void
    {
        // Tag "ally"; sheet fields "reflexes" (number 1 to 10, default 4) and "hp" (track, fixed max 40).
        $this->releases->add(new GameSystemSnapshot($key, $name, $version, null, [], [], tags: [new Tag('ally', 'Ally')], sheet: new SheetTemplate([new NumberField('reflexes', 'Reflexes', min: 1, max: 10, default: 4), new TrackField('hp', 'Hit points', max: 40)])));
    }

    #[Given('release version :version of the GameSystem :key named :name is published with Reflexes up to :max')]
    public function aReleaseCapsReflexes(int $version, string $key, string $name, int $max): void
    {
        // The character sheet release, its Reflexes capped at the given maximum.
        $this->releases->add(new GameSystemSnapshot($key, $name, $version, null, [], [], tags: [new Tag('ally', 'Ally')], sheet: new SheetTemplate([new NumberField('reflexes', 'Reflexes', min: 1, max: $max, default: 4), new TrackField('hp', 'Hit points', max: 40)])));
    }

    #[Given('I added the Character :name with Reflexes :reflexes')]
    public function iAddedTheCharacter(string $name, int $reflexes): void
    {
        $this->iAddTheCharacter($name, $reflexes);
        $this->assertNoFailure();
    }

    #[When('I add the Character :name with Reflexes :reflexes')]
    public function iAddTheCharacter(string $name, int $reflexes): void
    {
        $this->attempt(fn () => ($this->addCharacter)(new AddCharacter($this->campaignId(), self::ME, 'character-'.++$this->characterCount, $name, [], [], ['reflexes' => $reflexes])));
    }

    #[When('I add the Character :name without values')]
    public function iAddTheCharacterWithoutValues(string $name): void
    {
        $this->attempt(fn () => ($this->addCharacter)(new AddCharacter($this->campaignId(), self::ME, 'character-'.++$this->characterCount, $name, [], [], [])));
    }

    #[When('I edit the Character :name to have Reflexes :reflexes and :current hit points')]
    public function iEditTheCharacter(string $name, int $reflexes, int $current): void
    {
        $character = $this->character($name);
        $this->attempt(fn () => ($this->editCharacter)(new EditCharacter($this->campaignId(), self::ME, $character->id, $name, [], [], ['reflexes' => $reflexes, 'hp' => ['current' => $current]])));
    }

    #[When('I promote the NPC :name to a Character')]
    public function iPromoteTheNpc(string $name): void
    {
        $npc = $this->npc($name);
        $this->attempt(fn () => ($this->promoteNpc)(new PromoteNpc($this->campaignId(), self::ME, $npc->id, 'character-'.++$this->characterCount)));
    }

    #[Then('the NPC :name is closed and became the Character :character')]
    public function theNpcBecameTheCharacter(string $name, string $character): void
    {
        $npc = $this->npc($name);
        $promoted = $this->character($character);
        Assert::assertSame(['closed', $promoted->id], [$npc->status, $npc->promotedTo]);
        Assert::assertSame($npc->id, $promoted->promotedFrom);
    }

    #[Given('I added the NPC :name with the Tag :tag and the free tag :freeTag')]
    public function iAddedTheNpc(string $name, string $tag, string $freeTag): void
    {
        $this->iAddTheNpc($name, $tag, $freeTag);
        $this->assertNoFailure();
    }

    #[When('I add the NPC :name with the Tag :tag and the free tag :freeTag')]
    public function iAddTheNpc(string $name, string $tag, string $freeTag): void
    {
        $this->attempt(fn () => ($this->addNpc)(new AddNpc($this->campaignId(), self::ME, 'npc-'.++$this->npcCount, $name, null, [$tag], [$freeTag], 1)));
    }

    #[When('I try to add the NPC :name with the Tag :tag')]
    public function iTryToAddTheNpcWithTheTag(string $name, string $tag): void
    {
        $this->attempt(fn () => ($this->addNpc)(new AddNpc($this->campaignId(), self::ME, 'npc-'.++$this->npcCount, $name, null, [$tag], [], 1)));
    }

    #[When('I set the cast of the current scene to :names')]
    public function iSetTheCastOfTheCurrentScene(string $names): void
    {
        $ids = array_map(fn (string $name): string => $this->npc($name)->id, explode(', ', $names));
        $this->attempt(fn () => ($this->editCurrentSceneCast)(new EditCurrentSceneCast($this->campaignId(), self::ME, $ids)));
        $this->assertNoFailure();
    }

    #[When('I try to set the cast of the current scene to the ids :ids')]
    public function iTryToSetTheCastToTheIds(string $ids): void
    {
        $this->attempt(fn () => ($this->editCurrentSceneCast)(new EditCurrentSceneCast($this->campaignId(), self::ME, explode(', ', $ids))));
    }

    #[Then('the cast of the current scene is :names')]
    public function theCastOfTheCurrentSceneIs(string $names): void
    {
        $session = array_last($this->myCampaign()->sessions);
        $scene = null === $session ? null : array_last($session->scenes);
        Assert::assertNotNull($scene);
        Assert::assertSame(explode(', ', $names), array_map(static fn (CastMemberView $member): string => $member->name, $scene->cast));
    }

    #[Then('I am told that no NPC of the campaign has that id')]
    public function iAmToldNoNpcHasThatId(): void
    {
        Assert::assertInstanceOf(UnknownNpc::class, $this->failure);
    }

    #[Then('I am told that an NPC is in the cast twice')]
    public function iAmToldAnNpcIsInTheCastTwice(): void
    {
        Assert::assertInstanceOf(InvalidSceneCast::class, $this->failure);
    }

    #[Given('I added the free fact :label with the text :text')]
    public function iAddedTheFreeFact(string $label, string $text): void
    {
        $this->iAddTheFreeFact($label, $text);
        $this->assertNoFailure();
    }

    #[When('I add the free fact :label with the text :text')]
    public function iAddTheFreeFact(string $label, string $text): void
    {
        $this->attempt(fn () => ($this->addFact)(new AddFact($this->campaignId(), self::ME, 'fact-'.++$this->factCount, $label, $text, [])));
    }

    #[When('I add the free fact :label with the text :text linked to the NPC :name')]
    public function iAddTheFreeFactLinkedToTheNpc(string $label, string $text, string $name): void
    {
        $this->attempt(fn () => ($this->addFact)(new AddFact($this->campaignId(), self::ME, 'fact-'.++$this->factCount, $label, $text, [['kind' => 'npc', 'id' => $this->npc($name)->id]])));
        $this->assertNoFailure();
    }

    #[Given('I filled the fact slot :slot with the text :text')]
    public function iFilledTheFactSlotWithTheText(string $slot, string $text): void
    {
        $this->iFillTheFactSlotWithTheText($slot, $text);
        $this->assertNoFailure();
    }

    #[When('I fill the fact slot :slot with the text :text')]
    public function iFillTheFactSlotWithTheText(string $slot, string $text): void
    {
        $this->attempt(fn () => ($this->fillFactSlot)(new FillFactSlot($this->campaignId(), self::ME, $slot, $text, null, null)));
    }

    #[When('I fill the fact slot :slot with a new NPC named :name')]
    public function iFillTheFactSlotWithANewNpc(string $slot, string $name): void
    {
        $this->attempt(fn () => ($this->fillFactSlot)(new FillFactSlot($this->campaignId(), self::ME, $slot, null, null, $name)));
    }

    #[When('I link the slot fact :label to the NPC :name')]
    public function iLinkTheSlotFactToTheNpc(string $label, string $name): void
    {
        // A slot fact takes only links: its label is the slot's and its value changes by filling the slot.
        $fact = $this->fact($label);
        $this->attempt(fn () => ($this->editFact)(new EditFact($this->campaignId(), self::ME, $fact->id, null, null, [...$this->linksOf($fact), ['kind' => 'npc', 'id' => $this->npc($name)->id]])));
        $this->assertNoFailure();
    }

    #[When('I change the text of the fact :label to :text')]
    public function iChangeTheTextOfTheFact(string $label, string $text): void
    {
        $fact = $this->fact($label);
        $this->attempt(fn () => ($this->editFact)(new EditFact($this->campaignId(), self::ME, $fact->id, $fact->label, $text, $this->linksOf($fact))));
        $this->assertNoFailure();
    }

    #[When('I link the fact :label to the NPC :name')]
    public function iLinkTheFactToTheNpc(string $label, string $name): void
    {
        $this->iTryToLinkTheFactToTheNpc($label, $name);
        $this->assertNoFailure();
    }

    #[When('I try to link the fact :label to the NPC :name')]
    public function iTryToLinkTheFactToTheNpc(string $label, string $name): void
    {
        $fact = $this->fact($label);
        $links = [...$this->linksOf($fact), ['kind' => 'npc', 'id' => $this->npc($name)->id]];
        $this->attempt(fn () => ($this->editFact)(new EditFact($this->campaignId(), self::ME, $fact->id, $fact->label, $fact->text, $links)));
    }

    #[When('I try to fill the fact slot :slot with the text :text')]
    public function iTryToFillTheFactSlotWithTheText(string $slot, string $text): void
    {
        $this->iFillTheFactSlotWithTheText($slot, $text);
    }

    #[Then('I am told that the slot does not take that value')]
    public function iAmToldTheSlotDoesNotTakeThatValue(): void
    {
        Assert::assertInstanceOf(InvalidFact::class, $this->failure);
        Assert::assertSame('A "npc" slot takes an NPC.', $this->failure->getMessage());
    }

    #[Then('I am told that the fact links to the same NPC twice')]
    public function iAmToldTheFactLinksToTheSameNpcTwice(): void
    {
        Assert::assertInstanceOf(InvalidFact::class, $this->failure);
        Assert::assertStringContainsString('linked twice', $this->failure->getMessage());
    }

    #[When('I rename the NPC :name to :newName with the agenda :agenda and the weight :weight')]
    public function iEditTheNpc(string $name, string $newName, string $agenda, int $weight): void
    {
        $npc = $this->npc($name);
        $this->attempt(fn () => ($this->editNpc)(new EditNpc($this->campaignId(), self::ME, $npc->id, $newName, $agenda, $npc->tags, $npc->freeTags, $weight)));
        $this->assertNoFailure();
    }

    #[When('I close the NPC :name')]
    public function iCloseTheNpc(string $name): void
    {
        ($this->closeNpc)(new CloseNpc($this->campaignId(), self::ME, $this->npc($name)->id));
    }

    #[When('I reopen the NPC :name')]
    public function iReopenTheNpc(string $name): void
    {
        ($this->reopenNpc)(new ReopenNpc($this->campaignId(), self::ME, $this->npc($name)->id));
    }

    #[Given('I added the Thread :name with the Tag :tag and the free tag :freeTag')]
    public function iAddedTheThread(string $name, string $tag, string $freeTag): void
    {
        $this->iAddTheThread($name, $tag, $freeTag);
        $this->assertNoFailure();
    }

    #[When('I add the Thread :name with the Tag :tag and the free tag :freeTag')]
    public function iAddTheThread(string $name, string $tag, string $freeTag): void
    {
        $this->attempt(fn () => ($this->addThread)(new AddThread($this->campaignId(), self::ME, 'thread-'.++$this->threadCount, $name, [$tag], [$freeTag], 1)));
    }

    #[When('I try to add the Thread :name with the Tag :tag')]
    public function iTryToAddTheThreadWithTheTag(string $name, string $tag): void
    {
        $this->attempt(fn () => ($this->addThread)(new AddThread($this->campaignId(), self::ME, 'thread-'.++$this->threadCount, $name, [$tag], [], 1)));
    }

    #[When('I rename the Thread :name to :newName with the weight :weight')]
    public function iEditTheThread(string $name, string $newName, int $weight): void
    {
        $thread = $this->thread($name);
        $this->attempt(fn () => ($this->editThread)(new EditThread($this->campaignId(), self::ME, $thread->id, $newName, $thread->tags, $thread->freeTags, $weight)));
        $this->assertNoFailure();
    }

    #[When('I close the Thread :name')]
    public function iCloseTheThread(string $name): void
    {
        ($this->closeThread)(new CloseThread($this->campaignId(), self::ME, $this->thread($name)->id));
    }

    #[When('I reopen the Thread :name')]
    public function iReopenTheThread(string $name): void
    {
        ($this->reopenThread)(new ReopenThread($this->campaignId(), self::ME, $this->thread($name)->id));
    }

    #[When('I write the note :text')]
    public function iWriteTheNote(string $text): void
    {
        $this->iTryToWriteTheNote($text);
        $this->assertNoFailure();
    }

    #[When('I try to write the note :text')]
    public function iTryToWriteTheNote(string $text): void
    {
        $this->attempt(fn () => ($this->recordNote)(new RecordNote($this->entryId(), $this->campaignId(), self::ME, $text)));
    }

    #[When('I roll :expression and the dice show :numbers')]
    public function iRoll(string $expression, string $numbers): void
    {
        $handler = new RecordRollHandler($this->journal, $this->dice($numbers));
        $this->attempt(fn () => $handler(new RecordRoll($this->entryId(), $this->campaignId(), self::ME, $expression)));
        $this->assertNoFailure();
    }

    #[When('I roll on the oracle table :key and the dice show :numbers')]
    public function iRollOnTheOracleTable(string $key, string $numbers): void
    {
        $this->iTryToRollOnTheOracleTable($key, $numbers);
        $this->assertNoFailure();
    }

    #[When('I try to roll on the oracle table :key and the dice show :numbers')]
    public function iTryToRollOnTheOracleTable(string $key, string $numbers): void
    {
        $handler = new RecordOracleTableResultHandler($this->journal, $this->dice($numbers));
        $this->attempt(fn () => $handler(new RecordOracleTableResult($this->entryId(), $this->campaignId(), self::ME, $key)));
    }

    #[When('I ask the oracle :key :question as :likelihood with chaos factor :chaos and the dice show :numbers')]
    public function iAskTheOracle(string $key, string $question, string $likelihood, int $chaos, string $numbers): void
    {
        $handler = new RecordLikelihoodAnswerHandler($this->journal, $this->dice($numbers));
        $this->attempt(fn () => $handler(new RecordLikelihoodAnswer($this->entryId(), $this->campaignId(), self::ME, $key, $likelihood, $chaos, $question)));
        $this->assertNoFailure();
    }

    #[Given('the guided release of the GameSystem "guided" is published')]
    public function theGuidedReleaseIsPublished(): void
    {
        // Flows "tour" (the player picks "tour", whose setup holds six steps from the prompt
        // "intro", or "quiet") and "chain" (Solo scenes in a row, each closing with the mandatory
        // prompt "wrap").
        $this->releases->add(GuidedReleases::tour());
    }

    #[When('I pick the Scene Type :sceneType')]
    public function iPickTheSceneType(string $sceneType): void
    {
        $this->iTryToPickTheSceneType($sceneType);
        $this->assertNoFailure();
    }

    #[When('I try to pick the Scene Type :sceneType')]
    public function iTryToPickTheSceneType(string $sceneType): void
    {
        $this->attempt(fn () => ($this->pickSceneType)(new PickSceneType($this->campaignId(), self::ME, $sceneType)));
    }

    #[When('I answer the step :stepKey with :text')]
    public function iAnswerTheStep(string $stepKey, string $text): void
    {
        $this->attempt(fn () => ($this->completeFlowStep)(new CompleteFlowStep($this->campaignId(), self::ME, $this->entryId(), $stepKey, $text, null, null, null, null)));
        $this->assertNoFailure();
    }

    #[When('I skip the step :stepKey')]
    public function iSkipTheStep(string $stepKey): void
    {
        $this->iTryToSkipTheStep($stepKey);
        $this->assertNoFailure();
    }

    #[When('I try to skip the step :stepKey')]
    public function iTryToSkipTheStep(string $stepKey): void
    {
        $this->attempt(fn () => ($this->skipFlowStep)(new SkipFlowStep($this->campaignId(), self::ME, $stepKey)));
    }

    #[When('I end the guided scene :number')]
    public function iEndTheGuidedScene(int $number): void
    {
        $this->attempt(fn () => ($this->endFlowScene)(new EndFlowScene($this->campaignId(), self::ME, $number)));
        $this->assertNoFailure();
    }

    #[When('I pause the guidance')]
    public function iPauseTheGuidance(): void
    {
        $this->attempt(fn () => ($this->pauseGuidance)(new PauseGuidance($this->campaignId(), self::ME)));
        $this->assertNoFailure();
    }

    #[When('I resume the guidance')]
    public function iResumeTheGuidance(): void
    {
        $this->attempt(fn () => ($this->resumeGuidance)(new ResumeGuidance($this->campaignId(), self::ME)));
        $this->assertNoFailure();
    }

    #[Then('the FlowRun waits at the step :stepKey')]
    public function theFlowRunWaitsAtTheStep(string $stepKey): void
    {
        Assert::assertSame($stepKey, $this->flowRun()->step?->step->key);
    }

    #[Then('the FlowRun waits at the scene pick')]
    public function theFlowRunWaitsAtTheScenePick(): void
    {
        Assert::assertNotNull($this->flowRun()->pick);
        Assert::assertNull($this->flowRun()->step);
    }

    #[Then('the guidance is :status')]
    public function theGuidanceIs(string $status): void
    {
        Assert::assertSame(FlowRunStatus::from($status), $this->flowRun()->status);
    }

    #[Then('I am told the step is mandatory')]
    public function iAmToldTheStepIsMandatory(): void
    {
        Assert::assertInstanceOf(StepCannotBeSkipped::class, $this->failure);
    }

    #[Then('I am told the guidance is paused')]
    public function iAmToldTheGuidanceIsPaused(): void
    {
        Assert::assertInstanceOf(FlowRunNotActive::class, $this->failure);
    }

    #[Then('my journal holds, in order:')]
    public function myJournalHolds(TableNode $table): void
    {
        Assert::assertSame(
            array_map(
                static fn (array $row): array => [(int) $row['session'], (int) $row['scene'], $row['kind'], $row['summary']],
                $table->getColumnsHash(),
            ),
            array_map(
                static fn (JournalEntryView $entry): array => [$entry->sessionNumber, $entry->sceneNumber, $entry->kind, self::summary($entry)],
                $this->myJournal(),
            ),
        );
    }

    #[Then('my journal is empty')]
    public function myJournalIsEmpty(): void
    {
        Assert::assertSame([], $this->myJournal());
    }

    #[Then('I am told to start a scene first')]
    public function iAmToldToStartASceneFirst(): void
    {
        Assert::assertInstanceOf(NoCurrentScene::class, $this->failure);
    }

    #[Then('I am told that the GameSystem has no such oracle')]
    public function iAmToldTheGameSystemHasNoSuchOracle(): void
    {
        Assert::assertInstanceOf(UnknownGameSystemOracle::class, $this->failure);
    }

    #[Then('my campaign :name is pinned to version :version of :key named :gameSystemName')]
    public function myCampaignIsPinnedTo(string $name, int $version, string $key, string $gameSystemName): void
    {
        $campaign = $this->myCampaign();
        Assert::assertSame($name, $campaign->name);
        Assert::assertSame($key, $campaign->pinnedRelease->gameSystemKey);
        Assert::assertSame($version, $campaign->pinnedRelease->version);
        Assert::assertSame($gameSystemName, $campaign->pinnedRelease->gameSystemName);
    }

    #[Then('my campaign plays the Flow :flow')]
    public function myCampaignPlaysTheFlow(string $flow): void
    {
        Assert::assertSame($flow, $this->myCampaign()->flowKey);
    }

    #[Then('session :number has ended')]
    public function sessionHasEnded(int $number): void
    {
        Assert::assertNotNull($this->myCampaign()->sessions[$number - 1]->endedAt ?? null);
        Assert::assertNull($this->myCampaign()->currentSessionNumber);
    }

    #[Then("my campaign's Trackers are:")]
    public function myCampaignsTrackersAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['tracker'], (int) $row['value'], '' === $row['level'] ? null : $row['level']], $table->getColumnsHash()),
            array_map(static fn (TrackerView $tracker): array => [$tracker->key, $tracker->value, $tracker->levelLabel], $this->myCampaign()->trackers),
        );
    }

    #[Then('my facts are:')]
    public function myFactsAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['label'], $row['text'], $row['links']], $table->getColumnsHash()),
            array_map(static fn (FactView $fact): array => [$fact->label, $fact->value, implode(', ', array_map(static fn (FactLinkView $link): string => $link->name, $fact->links))], $this->myCampaign()->facts),
        );
    }

    #[Then('my campaign offers the sheet fields :keys')]
    public function myCampaignOffersTheSheetFields(string $keys): void
    {
        Assert::assertSame($keys, implode(', ', array_map(static fn (SheetFieldView $field): string => $field->key, $this->myCampaign()->pinnedRelease->sheet)));
    }

    #[Given('I previewed the release upgrade')]
    #[When('I preview the release upgrade')]
    public function iPreviewTheReleaseUpgrade(): void
    {
        $this->attempt(fn (): ReleaseUpgradePreview => $this->preview = ($this->previewReleaseUpgrade)(new PreviewReleaseUpgrade($this->campaignId(), self::ME)));
    }

    #[When('another player previews the release upgrade of my campaign')]
    public function anotherPlayerPreviewsTheReleaseUpgrade(): void
    {
        $this->attempt(fn (): ReleaseUpgradePreview => ($this->previewReleaseUpgrade)(new PreviewReleaseUpgrade($this->campaignId(), self::ANOTHER_PLAYER)));
    }

    #[When('I confirm the previewed release upgrade')]
    public function iConfirmThePreviewedReleaseUpgrade(): void
    {
        $plan = $this->preview->plan ?? throw new \LogicException('No upgrade was previewed.');
        $this->attempt(fn () => ($this->upgradeCampaignRelease)(new UpgradeCampaignRelease($this->campaignId(), self::ME, $plan->to->releaseVersion(), $plan->fingerprint())));
    }

    #[Then('the preview says the campaign is up to date')]
    public function thePreviewSaysTheCampaignIsUpToDate(): void
    {
        Assert::assertTrue($this->preview?->upToDate());
    }

    #[Then('the preview upgrades release :from to release :to and changes:')]
    public function thePreviewChanges(int $from, int $to, TableNode $table): void
    {
        $plan = $this->preview->plan ?? throw new \LogicException('The preview has no plan.');
        Assert::assertSame([$from, $to], [$plan->from->releaseVersion(), $plan->to->releaseVersion()]);
        $changes = [];
        foreach ($plan->characters as $character) {
            array_push($changes, ...array_map(static fn (FieldChange $change): array => [$character->name, $change->fieldName, $change->kind->value, (string) json_encode($change->before), (string) json_encode($change->after)], $character->changes));
        }

        Assert::assertSame(array_map(array_values(...), $table->getColumnsHash()), $changes);
    }

    #[Then('I am told that the upgrade changed since the preview')]
    public function iAmToldTheUpgradeChanged(): void
    {
        Assert::assertInstanceOf(ReleaseUpgradeChanged::class, $this->failure);
        $this->failure = null;
    }

    #[Then('my Characters are:')]
    public function myCharactersAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['name'], (int) $row['reflexes'], $row['hit points']], $table->getColumnsHash()),
            array_map(static fn (CharacterView $character): array => [$character->name, $character->values['reflexes'] ?? null, \sprintf('%d/%d', $character->values['hp']['current'] ?? -1, $character->values['hp']['max'] ?? -1)], $this->myCampaign()->characters),
        );
    }

    #[Then('I am told that the value breaks the sheet')]
    public function iAmToldTheValueBreaksTheSheet(): void
    {
        Assert::assertInstanceOf(InvalidCharacter::class, $this->failure);
        Assert::assertSame('Field "reflexes" must be 1 to 10, got 11.', $this->failure->getMessage());
    }

    #[Then('my NPCs are:')]
    public function myNpcsAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['name'], '' === $row['agenda'] ? null : $row['agenda'], $row['tags'], $row['free tags'], (int) $row['weight'], $row['status']], $table->getColumnsHash()),
            array_map(static fn (NpcView $npc): array => [$npc->name, $npc->agenda, implode(', ', $npc->tags), implode(', ', $npc->freeTags), $npc->weight, $npc->status], $this->myCampaign()->npcs),
        );
    }

    #[Then('my Threads are:')]
    public function myThreadsAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['name'], $row['tags'], $row['free tags'], (int) $row['weight'], $row['status']], $table->getColumnsHash()),
            array_map(static fn (ThreadView $thread): array => [$thread->name, implode(', ', $thread->tags), implode(', ', $thread->freeTags), $thread->weight, $thread->status], $this->myCampaign()->threads),
        );
    }

    #[Then('my campaign offers the Tags :keys')]
    public function myCampaignOffersTheTags(string $keys): void
    {
        Assert::assertSame(explode(', ', $keys), array_map(static fn (\App\Play\Application\TagView $tag): string => $tag->key, $this->myCampaign()->tags));
    }

    #[Then('I am told that the release has no such Tag')]
    public function iAmToldTheReleaseHasNoSuchTag(): void
    {
        Assert::assertInstanceOf(UnknownReleaseTag::class, $this->failure);
    }

    #[Then('my campaign has no session')]
    public function myCampaignHasNoSession(): void
    {
        Assert::assertSame([], $this->myCampaign()->sessions);
        Assert::assertNull($this->myCampaign()->currentSessionNumber);
    }

    #[Then('my campaign has :count sessions')]
    public function myCampaignHasSessions(int $count): void
    {
        Assert::assertCount($count, $this->myCampaign()->sessions);
    }

    #[Then('session :number has the scenes :titles')]
    public function sessionHasTheScenes(int $number, string $titles): void
    {
        $session = $this->myCampaign()->sessions[$number - 1] ?? null;
        Assert::assertNotNull($session);
        Assert::assertSame($number, $session->number);
        Assert::assertSame(
            explode(', ', $titles),
            array_map(static fn (SceneView $scene): string => $scene->title, $session->scenes),
        );
        Assert::assertSame(range(1, \count($session->scenes)), array_map(static fn (SceneView $scene): int => $scene->number, $session->scenes));
    }

    #[Then('the current scene is :title of the Scene Type :sceneTypeName')]
    public function theCurrentSceneIsOfTheSceneType(string $title, string $sceneTypeName): void
    {
        $session = array_last($this->myCampaign()->sessions);
        $scene = null === $session ? null : array_last($session->scenes);
        Assert::assertNotNull($scene);
        Assert::assertSame([$title, 'scene', $sceneTypeName], [$scene->title, $scene->kind, $scene->sceneTypeName]);
    }

    #[Then('the current session is :session and the current scene is :scene')]
    public function theCurrentSessionAndSceneAre(int $session, int $scene): void
    {
        Assert::assertSame($session, $this->myCampaign()->currentSessionNumber);
        Assert::assertSame($scene, $this->myCampaign()->currentSceneNumber);
    }

    #[Then('the current session is :session and there is no current scene')]
    public function theCurrentSessionHasNoScene(int $session): void
    {
        Assert::assertSame($session, $this->myCampaign()->currentSessionNumber);
        Assert::assertNull($this->myCampaign()->currentSceneNumber);
    }

    #[Then('I am told that the GameSystem has no published release')]
    public function iAmToldTheGameSystemHasNoRelease(): void
    {
        Assert::assertInstanceOf(GameSystemReleaseNotFound::class, $this->failure);
    }

    #[Then('I am told to start a session first')]
    public function iAmToldToStartASessionFirst(): void
    {
        Assert::assertInstanceOf(NoCurrentSession::class, $this->failure);
    }

    #[Then('I am told to end the session first')]
    public function iAmToldToEndTheSessionFirst(): void
    {
        Assert::assertInstanceOf(SessionAlreadyUnderWay::class, $this->failure);
    }

    #[Then('the campaign is not found')]
    public function theCampaignIsNotFound(): void
    {
        Assert::assertInstanceOf(CampaignNotFound::class, $this->failure);
        $this->failure = null;
    }

    #[Then('I have no campaigns')]
    public function iHaveNoCampaigns(): void
    {
        Assert::assertSame([], ($this->listMyCampaigns)(new ListMyCampaigns(self::ME)));
    }

    #[Then('another player has no campaigns')]
    public function anotherPlayerHasNoCampaigns(): void
    {
        Assert::assertSame([], ($this->listMyCampaigns)(new ListMyCampaigns(self::ANOTHER_PLAYER)));
        // ...while my own list still holds my campaign.
        Assert::assertSame([$this->campaignId()], array_map(static fn (CampaignSummaryView $campaign): string => $campaign->id, ($this->listMyCampaigns)(new ListMyCampaigns(self::ME))));
    }

    private function createCampaign(string $name, string $key, ?string $flow = null): void
    {
        $id = $this->ids->generate()->toString();
        $this->attempt(fn () => ($this->createCampaign)(new CreateCampaign($id, self::ME, $name, $key, $flow)));
        if (!$this->failure instanceof \Throwable) {
            $this->campaignId = $id;
        }
    }

    /**
     * @return list<JournalEntryView>
     */
    private function myJournal(): array
    {
        return ($this->getJournal)(new GetJournal($this->campaignId(), self::ME));
    }

    /**
     * One line per entry, enough to tell the entries apart in a feature table.
     */
    private static function summary(JournalEntryView $entry): string
    {
        $content = $entry->content;

        return match ($entry->kind) {
            'note' => self::text($content['text'] ?? null),
            'roll' => \sprintf('%s = %d', self::text($content['expression'] ?? null), self::number($content['total'] ?? null)),
            'oracle-table' => \sprintf('%s: %s', self::text($content['oracleName'] ?? null), implode(', ', array_map(
                static fn (mixed $step): string => \is_array($step) ? self::text($step['text'] ?? null) : '',
                \is_array($content['steps'] ?? null) ? $content['steps'] : [],
            ))),
            'likelihood' => \sprintf(
                '%s: %s %s, chaos %d: %s (%d vs %d)',
                self::text($content['oracleName'] ?? null),
                self::text($content['question'] ?? null),
                self::text($content['likelihoodLabel'] ?? null),
                self::number($content['chaosFactor'] ?? null),
                self::text($content['answer'] ?? null),
                self::number($content['roll'] ?? null),
                self::number($content['effectiveTarget'] ?? null),
            ),
            default => throw new \LogicException(\sprintf('Unknown journal entry kind "%s".', $entry->kind)),
        };
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('Expected a string in the entry content.');
    }

    private static function number(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \LogicException('Expected an integer in the entry content.');
    }

    /**
     * @param string $numbers what the dice show, in rolling order, e.g. "4, 5"
     */
    private function dice(string $numbers): ScriptedRandomNumberGenerator
    {
        return new ScriptedRandomNumberGenerator(...array_map(intval(...), explode(',', $numbers)));
    }

    private function entryId(): string
    {
        return $this->entryIds->generate()->toString();
    }

    private function assertNoFailure(): void
    {
        Assert::assertNull($this->failure, $this->failure?->getMessage() ?? '');
    }

    private function flowRun(): FlowRunView
    {
        return $this->myCampaign()->flowRun ?? throw new \LogicException('The campaign plays freely.');
    }

    private function myCampaign(): CampaignView
    {
        return ($this->getCampaign)(new GetCampaign($this->campaignId(), self::ME));
    }

    private function npc(string $name): NpcView
    {
        return array_find($this->myCampaign()->npcs, static fn (NpcView $npc): bool => $npc->name === $name) ?? throw new \LogicException(\sprintf('No NPC "%s".', $name));
    }

    private function fact(string $label): FactView
    {
        return array_find($this->myCampaign()->facts, static fn (FactView $fact): bool => $fact->label === $label) ?? throw new \LogicException(\sprintf('No fact "%s".', $label));
    }

    /**
     * @return list<array{kind: string, id: string}>
     */
    private function linksOf(FactView $fact): array
    {
        return array_map(static fn (FactLinkView $link): array => ['kind' => $link->kind, 'id' => $link->id], $fact->links);
    }

    private function thread(string $name): ThreadView
    {
        return array_find($this->myCampaign()->threads, static fn (ThreadView $thread): bool => $thread->name === $name) ?? throw new \LogicException(\sprintf('No Thread "%s".', $name));
    }

    private function character(string $name): CharacterView
    {
        return array_find($this->myCampaign()->characters, static fn (CharacterView $character): bool => $character->name === $name) ?? throw new \LogicException(\sprintf('No Character "%s".', $name));
    }

    private function campaignId(): string
    {
        return $this->campaignId ?? throw new \LogicException('No campaign was created.');
    }

    private function attempt(\Closure $action): void
    {
        $this->failure = null;

        try {
            $action();
        } catch (CampaignNotFound|NoCurrentSession|SessionAlreadyUnderWay|NoCurrentScene|GameSystemReleaseNotFound|UnknownGameSystemOracle|FlowRunNotActive|StepCannotBeSkipped|UnknownReleaseTag|UnknownNpc|InvalidSceneCast|InvalidFact|InvalidCharacter|UnknownCharacter|ReleaseUpgradeChanged|CampaignUpToDate $failure) {
            $this->failure = $failure;
        }
    }
}
