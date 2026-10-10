<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CampaignSummaryView;
use App\Play\Application\CampaignView;
use App\Play\Application\CreateCampaign;
use App\Play\Application\CreateCampaignHandler;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\GetJournal;
use App\Play\Application\GetJournalHandler;
use App\Play\Application\JournalEntryView;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\ListMyCampaignsHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordLikelihoodAnswerHandler;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordNoteHandler;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordOracleTableResultHandler;
use App\Play\Application\RecordRoll;
use App\Play\Application\RecordRollHandler;
use App\Play\Application\SceneView;
use App\Play\Application\SetTrackerValue;
use App\Play\Application\SetTrackerValueHandler;
use App\Play\Application\StartScene;
use App\Play\Application\StartSceneHandler;
use App\Play\Application\StartSession;
use App\Play\Application\StartSessionHandler;
use App\Play\Application\SwitchSceneType;
use App\Play\Application\SwitchSceneTypeHandler;
use App\Play\Application\TrackerView;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Tests\Support\Play\FixedClock;
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
    private readonly StartSceneHandler $startScene;
    private readonly SwitchSceneTypeHandler $switchSceneType;
    private readonly SetTrackerValueHandler $setTrackerValue;
    private readonly ListMyCampaignsHandler $listMyCampaigns;
    private readonly GetCampaignHandler $getCampaign;
    private readonly SequentialJournalEntryIdGenerator $entryIds;
    private readonly CampaignJournal $journal;
    private readonly RecordNoteHandler $recordNote;
    private readonly GetJournalHandler $getJournal;

    private ?string $campaignId = null;
    private ?\Throwable $failure = null;

    public function __construct()
    {
        $campaigns = new InMemoryCampaignRepository();
        $owned = new OwnedCampaigns($campaigns);
        $clock = new FixedClock();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->ids = new SequentialCampaignIdGenerator();
        $this->createCampaign = new CreateCampaignHandler($campaigns, $this->releases, $clock);
        $this->startSession = new StartSessionHandler($owned, $campaigns, $clock);
        $this->startScene = new StartSceneHandler($owned, $campaigns, $this->releases, $clock);
        $this->switchSceneType = new SwitchSceneTypeHandler($owned, $campaigns, $this->releases);
        $this->setTrackerValue = new SetTrackerValueHandler($owned, $campaigns, $this->releases);
        $this->listMyCampaigns = new ListMyCampaignsHandler($campaigns);
        $this->getCampaign = new GetCampaignHandler($owned, $this->releases);
        $entries = new InMemoryJournalEntryRepository();
        $this->entryIds = new SequentialJournalEntryIdGenerator();
        $this->journal = new CampaignJournal($owned, $entries, $this->releases, $clock);
        $this->recordNote = new RecordNoteHandler($this->journal);
        $this->getJournal = new GetJournalHandler($owned, $entries);
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

    #[Given('release version :version of the GameSystem :key named :name is published with Scene Types')]
    public function aReleaseWithSceneTypesIsPublished(int $version, string $key, string $name): void
    {
        // Scene Types "legwork" (Legwork) and "firefight" (Firefight).
        $this->releases->add(Snapshots::withSceneTypes($key, $name, $version));
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

    #[Then("my campaign's Trackers are:")]
    public function myCampaignsTrackersAre(TableNode $table): void
    {
        Assert::assertSame(
            array_map(static fn (array $row): array => [$row['tracker'], (int) $row['value'], '' === $row['level'] ? null : $row['level']], $table->getColumnsHash()),
            array_map(static fn (TrackerView $tracker): array => [$tracker->key, $tracker->value, $tracker->levelLabel], $this->myCampaign()->trackers),
        );
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

    private function createCampaign(string $name, string $key): void
    {
        $id = $this->ids->generate()->toString();
        $this->attempt(fn () => ($this->createCampaign)(new CreateCampaign($id, self::ME, $name, $key)));
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

    private function myCampaign(): CampaignView
    {
        return ($this->getCampaign)(new GetCampaign($this->campaignId(), self::ME));
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
        } catch (CampaignNotFound|NoCurrentSession|NoCurrentScene|GameSystemReleaseNotFound|UnknownGameSystemOracle $failure) {
            $this->failure = $failure;
        }
    }
}
