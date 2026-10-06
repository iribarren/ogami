<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\CampaignSummaryView;
use App\Play\Application\CampaignView;
use App\Play\Application\CreateCampaign;
use App\Play\Application\CreateCampaignHandler;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\ListMyCampaignsHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\SceneView;
use App\Play\Application\StartScene;
use App\Play\Application\StartSceneHandler;
use App\Play\Application\StartSession;
use App\Play\Application\StartSessionHandler;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\SequentialCampaignIdGenerator;
use App\Tests\Support\Play\Snapshots;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Playing campaigns, on in-memory fakes (no kernel). "I" is one solo player, "another player" a
 * second one.
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
    private readonly ListMyCampaignsHandler $listMyCampaigns;
    private readonly GetCampaignHandler $getCampaign;

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
        $this->startScene = new StartSceneHandler($owned, $campaigns, $clock);
        $this->listMyCampaigns = new ListMyCampaignsHandler($campaigns);
        $this->getCampaign = new GetCampaignHandler($owned, $this->releases);
    }

    // Behat matches steps by text whatever the keyword, so one attribute serves Given and When.
    #[Given('release version :version of the GameSystem :key named :name is published')]
    public function aReleaseIsPublished(int $version, string $key, string $name): void
    {
        $this->releases->add(Snapshots::bare($key, $name, $version));
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

    #[Then('my campaign :name is pinned to version :version of :key named :gameSystemName')]
    public function myCampaignIsPinnedTo(string $name, int $version, string $key, string $gameSystemName): void
    {
        $campaign = $this->myCampaign();
        Assert::assertSame($name, $campaign->name);
        Assert::assertSame($key, $campaign->pinnedRelease->gameSystemKey);
        Assert::assertSame($version, $campaign->pinnedRelease->version);
        Assert::assertSame($gameSystemName, $campaign->pinnedRelease->gameSystemName);
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
        } catch (CampaignNotFound|NoCurrentSession|GameSystemReleaseNotFound $failure) {
            $this->failure = $failure;
        }
    }
}
