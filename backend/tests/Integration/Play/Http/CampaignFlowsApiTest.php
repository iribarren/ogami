<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignResponse;
use App\Play\Infrastructure\Http\FlowSummaryResponse;
use App\Play\Infrastructure\Http\GameSystemController;
use App\Play\Infrastructure\Http\GameSystemFlowResponse;
use App\Play\Infrastructure\Http\SceneTypeSummaryResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Choosing a Flow when a campaign is created, on the schema version 2 release "valid/v2-every-part"
 * (GameSystem "every-part"; Flows "heist", the default, and "quick").
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(GameSystemController::class)]
#[CoversClass(CampaignResponse::class)]
#[CoversClass(FlowSummaryResponse::class)]
#[CoversClass(GameSystemFlowResponse::class)]
#[CoversClass(SceneTypeSummaryResponse::class)]
final class CampaignFlowsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private const array FLOWS = [
        ['key' => 'heist', 'name' => 'Heist', 'description' => 'Plan the job, break in, get out.', 'introduction' => 'Learn what you can, then go in. Watch the alarm.', 'default' => true, 'defaultView' => 'focus'],
        ['key' => 'quick', 'name' => 'Quick job', 'description' => null, 'introduction' => null, 'default' => false, 'defaultView' => 'journal'],
    ];

    private const array SCENE_TYPES = [
        ['key' => 'legwork', 'name' => 'Legwork', 'purpose' => 'Learn about the target.'],
        ['key' => 'infiltration', 'name' => 'Infiltration', 'purpose' => 'Get inside unseen.'],
        ['key' => 'firefight', 'name' => 'Firefight', 'purpose' => 'Shoot your way out.'],
        ['key' => 'getaway', 'name' => 'Getaway', 'purpose' => 'Get away clean.'],
        ['key' => 'downtime', 'name' => 'Downtime', 'purpose' => 'Rest between jobs; no flow reaches it.'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9301', ReleaseViews::fixtureContent('valid/v2-every-part'), false));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
    }

    #[Test]
    public function theCatalogListsTheFlowsOfEachLatestRelease(): void
    {
        $this->client->request('GET', '/api/play/game-systems');

        self::assertResponseIsSuccessful();
        $catalog = $this->json();
        self::assertCount(1, $catalog);
        self::assertIsArray($catalog[0]);
        self::assertSame('every-part', $catalog[0]['gameSystemKey']);
        self::assertSame([
            ['key' => 'heist', 'name' => 'Heist', 'description' => 'Plan the job, break in, get out.', 'default' => true],
            ['key' => 'quick', 'name' => 'Quick job', 'description' => null, 'default' => false],
        ], $catalog[0]['flows']);
    }

    #[Test]
    public function aCampaignPlaysTheFlowChosenAndShowsTheFlowsAndSceneTypesOfItsRelease(): void
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'every-part', 'flowKey' => 'quick']);

        self::assertResponseStatusCodeSame(201);
        $campaign = $this->json();
        self::assertSame(['quick', self::FLOWS, self::SCENE_TYPES], [$campaign['flowKey'], $campaign['flows'], $campaign['sceneTypes']]);

        self::assertIsString($campaign['id']);
        $this->client->request('GET', '/api/campaigns/'.$campaign['id']);
        self::assertSame('quick', $this->json()['flowKey'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, ?string>}>
     */
    public static function freePlayBodies(): iterable
    {
        yield 'no Flow key' => [['name' => 'The job', 'gameSystemKey' => 'every-part']];
        yield 'a null Flow key' => [['name' => 'The job', 'gameSystemKey' => 'every-part', 'flowKey' => null]];
    }

    /**
     * @param array<string, ?string> $body
     */
    #[Test]
    #[DataProvider('freePlayBodies')]
    public function withoutAFlowKeyTheCampaignPlaysFreelyEvenWithADefaultFlow(array $body): void
    {
        $this->client->jsonRequest('POST', '/api/campaigns', $body);

        self::assertResponseStatusCodeSame(201);
        $campaign = $this->json();
        self::assertSame([null, self::FLOWS], [$campaign['flowKey'], $campaign['flows']]);
    }

    #[Test]
    public function aFlowTheReleaseDoesNotHaveIsNotFoundAndCreatesNoCampaign(): void
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'every-part', 'flowKey' => 'the-long-con']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Flow "the-long-con" not found.'], $this->json());

        $this->client->request('GET', '/api/campaigns');
        self::assertSame([], $this->json());
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
