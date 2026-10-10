<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Hook;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\SceneResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Scenes with a Scene Type over the Play API, on the schema version 2 release
 * "valid/v2-scene-types" (Scene Types "legwork" Legwork, "infiltration", "firefight" Firefight
 * and "getaway").
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(SceneResponse::class)]
final class CampaignScenesApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string STARTED_AT = '2026-10-10T09:00:00+00:00';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container across requests, so the fixed clock below is the one the requests use.
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock(self::STARTED_AT));
        $container->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9101', ReleaseViews::fixtureContent('valid/v2-scene-types'), false));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
    }

    #[Test]
    public function aSceneWithASceneTypeAndNoTitleIsNamedAfterItNumberedPerType(): void
    {
        $id = $this->campaignInASession();

        $this->startScene($id, ['sceneType' => 'legwork']);
        $this->startScene($id, ['sceneType' => 'firefight', 'title' => null]);
        $this->startScene($id, ['title' => 'Casing the bank', 'sceneType' => 'legwork']);
        $this->startScene($id, ['title' => 'A quiet drink']);
        $this->startScene($id, ['sceneType' => 'legwork']);

        self::assertSame([
            $this->scene(1, 'Legwork 1', 'legwork', 'Legwork'),
            $this->scene(2, 'Firefight 1', 'firefight', 'Firefight'),
            $this->scene(3, 'Casing the bank', 'legwork', 'Legwork'),
            $this->scene(4, 'A quiet drink', null, null),
            $this->scene(5, 'Legwork 3', 'legwork', 'Legwork'),
        ], $this->scenes());
    }

    #[Test]
    public function anUnknownSceneTypeIsNotFoundAndStartsNoScene(): void
    {
        $id = $this->campaignInASession();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['sceneType' => 'heist']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Scene Type "heist" not found.'], $this->json());
        self::assertSame([], $this->scenes($id));
    }

    #[Test]
    public function theCurrentSceneSwitchesItsSceneTypeByHandKeepingItsTitle(): void
    {
        $id = $this->campaignInASession();
        $this->startScene($id, ['title' => 'At the gate']);
        $this->startScene($id, ['sceneType' => 'legwork']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), ['sceneType' => 'firefight']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->scene(2, 'Legwork 1', 'firefight', 'Firefight'), $this->json());
        self::assertSame([$this->scene(1, 'At the gate', null, null), $this->scene(2, 'Legwork 1', 'firefight', 'Firefight')], $this->scenes($id));
    }

    #[Test]
    public function switchingToAnUnknownSceneTypeIsNotFound(): void
    {
        $id = $this->campaignInASession();
        $this->startScene($id, ['sceneType' => 'legwork']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), ['sceneType' => 'heist']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Scene Type "heist" not found.'], $this->json());
        self::assertSame([$this->scene(1, 'Legwork 1', 'legwork', 'Legwork')], $this->scenes($id));
    }

    #[Test]
    public function switchingNeedsACurrentSceneOfPlay(): void
    {
        $id = $this->campaignInASession();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), ['sceneType' => 'legwork']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a scene before switching its Scene Type.'], $this->json());

        // Hook Scenes are not started over the API yet (the FlowRun starts them).
        $campaigns = self::getContainer()->get(CampaignRepository::class);
        $campaign = $campaigns->ofId(CampaignId::fromString($id)) ?? throw new \LogicException('No campaign.');
        $campaign->startHookScene(Hook::WorldTurn, 'The world moves', new \DateTimeImmutable(self::STARTED_AT));
        $campaigns->save($campaign);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), ['sceneType' => 'legwork']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The current scene is a "worldTurn" hook scene: only a scene of play has a Scene Type to switch.'], $this->json());
        self::assertSame([['number' => 1, 'title' => 'The world moves', 'startedAt' => self::STARTED_AT, 'kind' => 'hook', 'sceneType' => null, 'sceneTypeName' => null, 'hook' => 'worldTurn']], $this->scenes($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSwitchBodies(): iterable
    {
        yield 'invalid JSON' => ['{"sceneType": '];
        yield 'no Scene Type' => ['{}'];
        yield 'a null Scene Type' => ['{"sceneType": null}'];
        yield 'a non-string Scene Type' => ['{"sceneType": 3}'];
    }

    #[Test]
    #[DataProvider('malformedSwitchBodies')]
    public function aMalformedSwitchBodyIsABadRequest(string $body): void
    {
        $id = $this->campaignInASession();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with a string "sceneType", such as {"sceneType": "firefight"}.'], $this->json());
    }

    #[Test]
    public function aFormEncodedSwitchBodyIsRejected(): void
    {
        $id = $this->campaignInASession();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/scenes/current/scene-type', $id), ['sceneType' => 'legwork']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the Scene Type as JSON.'], $this->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function scene(int $number, string $title, ?string $sceneType, ?string $sceneTypeName): array
    {
        return ['number' => $number, 'title' => $title, 'startedAt' => self::STARTED_AT, 'kind' => 'scene', 'sceneType' => $sceneType, 'sceneTypeName' => $sceneTypeName, 'hook' => null];
    }

    /**
     * @param array<string, ?string> $body
     */
    private function startScene(string $campaignId, array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return mixed the scenes of the first session, from the last response or, with an id, from the campaign
     */
    private function scenes(?string $campaignId = null): mixed
    {
        if (null !== $campaignId) {
            $this->client->request('GET', '/api/campaigns/'.$campaignId);
            self::assertResponseIsSuccessful();
        }

        $sessions = $this->json()['sessions'] ?? null;
        self::assertIsArray($sessions);
        self::assertIsArray($sessions[0] ?? null);

        return $sessions[0]['scenes'] ?? null;
    }

    private function campaignInASession(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'scene-types']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);

        return $id;
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
