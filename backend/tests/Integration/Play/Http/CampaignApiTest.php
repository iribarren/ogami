<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignResponse;
use App\Play\Infrastructure\Http\CampaignSummaryResponse;
use App\Play\Infrastructure\Http\GameSystemController;
use App\Play\Infrastructure\Http\GameSystemSummaryResponse;
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
 * Campaigns over the Play API: the release catalog, campaigns, sessions and scenes. Every Play
 * endpoint is for solo players only, and a campaign is visible to its owner only.
 */
#[CoversClass(GameSystemController::class)]
#[CoversClass(GameSystemSummaryResponse::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(CampaignSummaryResponse::class)]
#[CoversClass(CampaignResponse::class)]
final class CampaignApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private KernelBrowser $client;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container across requests, so the fixed clock below is the one the requests use.
        $this->client->disableReboot();
        $this->clock = new FixedClock('2026-10-06T09:00:00+00:00');
        self::getContainer()->set(Clock::class, $this->clock);
    }

    #[Test]
    public function aSoloPlayerListsTheLatestReleaseOfEachGameSystem(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6002', 'Example journal, revised');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/play/game-systems');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                [
                    {
                        "gameSystemKey": "example-journal",
                        "name": "Example journal, revised",
                        "description": "A minimal game system that shows every part of the contract.",
                        "version": 2
                    }
                ]
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function aSoloPlayerCreatesACampaignPinnedToTheLatestRelease(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => '  The lost mine ', 'gameSystemKey' => 'example-journal']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $id);
        self::assertResponseHeaderSame('Location', '/api/campaigns/'.$id);
        self::assertJsonStringEqualsJsonString($this->newCampaignJson($id, 'The lost mine'), $this->content());
    }

    #[Test]
    public function aSoloPlayerReadsTheirCampaign(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString($this->newCampaignJson($id, 'The lost mine'), $this->content());
    }

    #[Test]
    public function aSoloPlayerListsTheirOwnCampaignsNewestFirst(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $this->createCampaign('Not mine');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $first = $this->createCampaign('The lost mine');
        $this->clock->moveTo('2026-10-06T10:30:00+00:00');
        $second = $this->createCampaign('The sunken keep');

        $this->client->request('GET', '/api/campaigns');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode([
                [
                    'id' => $second,
                    'name' => 'The sunken keep',
                    'gameSystemKey' => 'example-journal',
                    'gameSystemName' => 'Example journal',
                    'releaseVersion' => 1,
                    'createdAt' => '2026-10-06T10:30:00+00:00',
                ],
                [
                    'id' => $first,
                    'name' => 'The lost mine',
                    'gameSystemKey' => 'example-journal',
                    'gameSystemName' => 'Example journal',
                    'releaseVersion' => 1,
                    'createdAt' => '2026-10-06T09:00:00+00:00',
                ],
            ], \JSON_THROW_ON_ERROR),
            $this->content(),
        );
    }

    #[Test]
    public function aSoloPlayerWithoutCampaignsGetsAnEmptyList(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/campaigns');

        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->content());
    }

    #[Test]
    public function aSoloPlayerStartsSessionsAndScenes(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');

        $this->clock->moveTo('2026-10-06T09:05:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [[1, '2026-10-06T09:05:00+00:00', []], 1, null],
            $this->sessionsSummary(),
        );

        $this->clock->moveTo('2026-10-06T09:10:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => '  At the gate ']);
        self::assertResponseStatusCodeSame(201);

        $this->clock->moveTo('2026-10-06T09:20:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);

        self::assertJsonStringEqualsJsonString(
            json_encode([
                'id' => $id,
                'name' => 'The lost mine',
                'createdAt' => '2026-10-06T09:00:00+00:00',
                'pinnedRelease' => ['gameSystemKey' => 'example-journal', 'gameSystemName' => 'Example journal', 'version' => 1],
                'sessions' => [
                    [
                        'number' => 1,
                        'startedAt' => '2026-10-06T09:05:00+00:00',
                        'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-06T09:10:00+00:00']],
                    ],
                    ['number' => 2, 'startedAt' => '2026-10-06T09:20:00+00:00', 'scenes' => []],
                ],
                'currentSessionNumber' => 2,
                'currentSceneNumber' => null,
                'oracleTables' => self::ORACLE_TABLES,
                'likelihoodOracles' => self::LIKELIHOOD_ORACLES,
            ], \JSON_THROW_ON_ERROR),
            $this->content(),
        );
    }

    #[Test]
    public function aCampaignStaysPinnedAfterANewerReleaseIsPublished(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6002', 'Example journal, revised', withoutLikelihoodOracles: true);

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString($this->newCampaignJson($id, 'The lost mine'), $this->content());

        $this->client->request('GET', '/api/campaigns');
        $campaigns = $this->json();
        self::assertIsArray($campaigns[0] ?? null);
        self::assertSame(1, $campaigns[0]['releaseVersion'] ?? null);

        // A new campaign pins the newer release.
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'Fresh start', 'gameSystemKey' => 'example-journal']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['gameSystemKey' => 'example-journal', 'gameSystemName' => 'Example journal, revised', 'version' => 2],
            $this->json()['pinnedRelease'] ?? null,
        );
        self::assertSame([], $this->json()['likelihoodOracles'] ?? null);
    }

    #[Test]
    public function aSceneNeedsASession(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a session before starting a scene.'], $this->json());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCampaignNames(): iterable
    {
        yield 'blank' => ['   ', 'A campaign name must not be blank.'];
        yield 'too long' => [str_repeat('a', Campaign::MAX_NAME_LENGTH + 1), 'A campaign name must be at most 100 characters, got 101.'];
    }

    #[Test]
    #[DataProvider('invalidCampaignNames')]
    public function anInvalidCampaignNameIsUnprocessable(string $name, string $error): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => $name, 'gameSystemKey' => 'example-journal']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        $this->client->request('GET', '/api/campaigns');
        self::assertSame('[]', $this->content());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSceneTitles(): iterable
    {
        yield 'blank' => ['  ', 'A scene title must not be blank.'];
        yield 'too long' => [str_repeat('a', 101), 'A scene title must be at most 100 characters, got 101.'];
    }

    #[Test]
    #[DataProvider('invalidSceneTitles')]
    public function anInvalidSceneTitleIsUnprocessable(string $title, string $error): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => $title]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
    }

    #[Test]
    public function anUnknownGameSystemIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The lost mine', 'gameSystemKey' => 'no-such-system']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'No published release of GameSystem "no-such-system" is available to Play.'], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCampaignBodies(): iterable
    {
        yield 'invalid JSON' => ['{"name": '];
        yield 'not an object' => ['"The lost mine"'];
        yield 'missing name' => ['{"gameSystemKey": "example-journal"}'];
        yield 'missing game system' => ['{"name": "The lost mine"}'];
        yield 'non-string name' => ['{"name": 7, "gameSystemKey": "example-journal"}'];
        yield 'null game system' => ['{"name": "The lost mine", "gameSystemKey": null}'];
    }

    #[Test]
    #[DataProvider('malformedCampaignBodies')]
    public function aMalformedCampaignBodyIsABadRequest(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('POST', '/api/campaigns', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with a string "name" and a string "gameSystemKey", such as {"name": "The lost mine", "gameSystemKey": "ironsworn"}.'], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSceneBodies(): iterable
    {
        yield 'invalid JSON' => ['{"title": '];
        yield 'missing title' => ['{}'];
        yield 'non-string title' => ['{"title": 3}'];
    }

    #[Test]
    #[DataProvider('malformedSceneBodies')]
    public function aMalformedSceneBodyIsABadRequest(string $body): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('The lost mine');

        $this->client->request('POST', \sprintf('/api/campaigns/%s/scenes', $id), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with a string "title", such as {"title": "At the gate"}.'], $this->json());
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('POST', '/api/campaigns', ['name' => 'The lost mine', 'gameSystemKey' => 'example-journal']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the campaign as JSON.'], $this->json());
    }

    /**
     * @return iterable<string, array{string, string, ?array<string, string>}>
     */
    public static function campaignEndpoints(): iterable
    {
        yield 'get' => ['GET', '/api/campaigns/%s', null];
        yield 'start session' => ['POST', '/api/campaigns/%s/sessions', null];
        yield 'start scene' => ['POST', '/api/campaigns/%s/scenes', ['title' => 'At the gate']];
    }

    /**
     * @param ?array<string, string> $body
     */
    #[Test]
    #[DataProvider('campaignEndpoints')]
    public function anotherPlayersCampaignIsNotFound(string $method, string $path, ?array $body): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign('Bob only');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->jsonRequest($method, \sprintf($path, $id), $body ?? []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
    }

    /**
     * @param ?array<string, string> $body
     */
    #[Test]
    #[DataProvider('campaignEndpoints')]
    public function anUnknownOrMalformedCampaignIdIsNotFound(string $method, string $path, ?array $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach (['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999', 'not-a-uuid'] as $id) {
            $this->client->jsonRequest($method, \sprintf($path, $id), $body ?? []);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
        }
    }

    /**
     * @return iterable<string, array{string, string, ?array<string, string>}>
     */
    public static function playEndpoints(): iterable
    {
        yield 'list game systems' => ['GET', '/api/play/game-systems', null];
        yield 'list campaigns' => ['GET', '/api/campaigns', null];
        yield 'create campaign' => ['POST', '/api/campaigns', ['name' => 'The lost mine', 'gameSystemKey' => 'example-journal']];
        yield 'get campaign' => ['GET', '/api/campaigns/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999', null];
        yield 'start session' => ['POST', '/api/campaigns/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999/sessions', null];
        yield 'start scene' => ['POST', '/api/campaigns/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999/scenes', ['title' => 'At the gate']];
    }

    /**
     * @param ?array<string, string> $body
     */
    #[Test]
    #[DataProvider('playEndpoints')]
    public function playNeedsASession(string $method, string $path, ?array $body): void
    {
        $this->client->jsonRequest($method, $path, $body ?? []);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'Authentication required.'], $this->json());
    }

    /**
     * @param ?array<string, string> $body
     */
    #[Test]
    #[DataProvider('playEndpoints')]
    public function playIsForSoloPlayersOnly(string $method, string $path, ?array $body): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            $this->client->jsonRequest($method, $path, $body ?? []);

            self::assertResponseStatusCodeSame(403);
            self::assertSame(['error' => 'Access denied.'], $this->json());
        }
    }

    private const array ORACLE_TABLES = [
        ['key' => 'weather', 'name' => 'Weather'],
        ['key' => 'storm-kind', 'name' => 'Storm kind'],
    ];

    private const array LIKELIHOOD_ORACLES = [
        [
            'key' => 'fate',
            'name' => 'Fate question',
            'levels' => [
                ['key' => 'unlikely', 'label' => 'Unlikely'],
                ['key' => 'even', 'label' => '50/50'],
                ['key' => 'likely', 'label' => 'Likely'],
            ],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5],
        ],
    ];

    private function newCampaignJson(string $id, string $name): string
    {
        return json_encode([
            'id' => $id,
            'name' => $name,
            'createdAt' => '2026-10-06T09:00:00+00:00',
            'pinnedRelease' => ['gameSystemKey' => 'example-journal', 'gameSystemName' => 'Example journal', 'version' => 1],
            'sessions' => [],
            'currentSessionNumber' => null,
            'currentSceneNumber' => null,
            'oracleTables' => self::ORACLE_TABLES,
            'likelihoodOracles' => self::LIKELIHOOD_ORACLES,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{mixed, mixed, mixed} the first session as [number, startedAt, scenes], then the current numbers
     */
    private function sessionsSummary(): array
    {
        $campaign = $this->json();
        $sessions = $campaign['sessions'] ?? null;
        self::assertIsArray($sessions);
        $first = $sessions[0] ?? null;
        self::assertIsArray($first);

        return [[$first['number'] ?? null, $first['startedAt'] ?? null, $first['scenes'] ?? null], $campaign['currentSessionNumber'] ?? null, $campaign['currentSceneNumber'] ?? null];
    }

    private function publish(string $releaseId, ?string $name = null, bool $withoutLikelihoodOracles = false): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        if (null !== $name) {
            /** @var array<string, mixed> $gameSystem */
            $gameSystem = $content['gameSystem'];
            $gameSystem['name'] = $name;
            $content['gameSystem'] = $gameSystem;
        }

        if ($withoutLikelihoodOracles) {
            /** @var array<string, mixed> $oracles */
            $oracles = $content['oracles'];
            $oracles['likelihood'] = [];
            $content['oracles'] = $oracles;
        }

        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease($releaseId, $content, false));
    }

    private function createCampaign(string $name): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => $name, 'gameSystemKey' => 'example-journal']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

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

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
