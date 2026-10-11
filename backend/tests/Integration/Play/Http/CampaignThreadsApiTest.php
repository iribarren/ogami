<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\ThreadIdGenerator;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignThreadsController;
use App\Play\Infrastructure\Http\TagResponse;
use App\Play\Infrastructure\Http\ThreadResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Campaign Threads over the Play API, on the schema version 2 release "valid/v2-threads-npcs", whose
 * Tags are "leader" (Leader, with a hint), "ally" (Ally) and "hostile" (Hostile, with a hint).
 */
#[CoversClass(CampaignThreadsController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(ThreadResponse::class)]
#[CoversClass(TagResponse::class)]
final class CampaignThreadsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with a string "name" and, optionally, lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Rob the vault", "tags": ["heist"], "weight": 2}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseViews::fixtureContent('valid/v2-threads-npcs'), false));
    }

    #[Test]
    public function aThreadIsAddedWithItsDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => '  Rob the vault ']);

        self::assertResponseStatusCodeSame(201);
        $thread = $this->json();
        self::assertIsString($thread['id'] ?? null);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $thread['id']);
        self::assertSame(['name' => 'Rob the vault', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], array_diff_key($thread, ['id' => 1]));
        self::assertSame([$thread], $this->campaignThreads($id));
    }

    #[Test]
    public function aThreadIsAddedWithEveryField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Rob the vault', 'tags' => ['ally', 'leader'], 'freeTags' => ['Fence'], 'weight' => 3]);

        self::assertResponseStatusCodeSame(201);
        $thread = $this->json();
        self::assertSame(['Rob the vault', ['ally', 'leader'], ['Fence'], 3, 'open'], [$thread['name'] ?? null, $thread['tags'] ?? null, $thread['freeTags'] ?? null, $thread['weight'] ?? null, $thread['status'] ?? null]);
        self::assertSame([$thread], $this->campaignThreads($id));
    }

    #[Test]
    public function nullOptionalFieldsReadAsTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Rob the vault', 'tags' => null, 'freeTags' => null, 'weight' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['name' => 'Rob the vault', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], array_diff_key($this->json(), ['id' => 1]));
    }

    #[Test]
    public function anEditReplacesEveryFieldAndOmittedOnesTakeTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault', 'tags' => ['ally'], 'freeTags' => ['Fence'], 'weight' => 3]);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads/%s/close', $id, $threadId));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId), ['name' => 'Rob the bank', 'tags' => ['hostile']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $threadId, 'name' => 'Rob the bank', 'tags' => ['hostile'], 'freeTags' => [], 'weight' => 1, 'status' => 'closed'], $this->json());
        self::assertSame([$this->json()], $this->campaignThreads($id));
    }

    #[Test]
    public function closingAndReopeningAreIdempotentAndOpenThreadsListFirst(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $vault = $this->addThread($id, ['name' => 'Rob the vault']);
        $ledger = $this->addThread($id, ['name' => 'Find the ledger']);

        foreach (['close', 'close'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads/%s/%s', $id, $vault, $action));
            self::assertResponseIsSuccessful();
            self::assertSame(['Rob the vault', 'closed'], [$this->json()['name'] ?? null, $this->json()['status'] ?? null]);
        }

        self::assertSame([[$ledger, 'open'], [$vault, 'closed']], array_map(static fn (array $thread): array => [$thread['id'], $thread['status']], $this->campaignThreads($id)));

        foreach (['reopen', 'reopen'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads/%s/%s', $id, $vault, $action));
            self::assertResponseIsSuccessful();
            self::assertSame('open', $this->json()['status'] ?? null);
        }

        self::assertSame([[$vault, 'open'], [$ledger, 'open']], array_map(static fn (array $thread): array => [$thread['id'], $thread['status']], $this->campaignThreads($id)));
    }

    #[Test]
    public function tagsAreCheckedAgainstThePinnedReleaseNotTheLatestOne(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $content = ReleaseViews::fixtureContent('valid/v2-threads-npcs');
        /** @var list<array<string, mixed>> $tags */
        $tags = $content['tags'];
        $content['tags'] = [...$tags, ['key' => 'newcomer', 'label' => 'Newcomer']];
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9002', $content, false));

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Rob the vault', 'tags' => ['newcomer']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Tag "newcomer" not found in the pinned release.'], $this->json());
        self::assertSame(['leader', 'ally', 'hostile'], array_column($this->campaignTags($id), 'key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not an object' => ['[3]'];
        yield 'invalid JSON' => ['{"name":'];
        yield 'no name' => ['{}'];
        yield 'a null name' => ['{"name": null}'];
        yield 'a numeric name' => ['{"name": 3}'];
        yield 'tags as a string' => ['{"name": "Rob the vault", "tags": "ally"}'];
        yield 'tags with a number' => ['{"name": "Rob the vault", "tags": [3]}'];
        yield 'tags as an object' => ['{"name": "Rob the vault", "tags": {"a": "ally"}}'];
        yield 'free tags as a string' => ['{"name": "Rob the vault", "freeTags": "Fence"}'];
        yield 'free tags with a null' => ['{"name": "Rob the vault", "freeTags": [null]}'];
        yield 'a string weight' => ['{"name": "Rob the vault", "weight": "2"}'];
        yield 'a decimal weight' => ['{"name": "Rob the vault", "weight": 2.5}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/threads', $id)], ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => self::MALFORMED], $this->json());
        }

        self::assertSame(['Rob the vault'], array_column($this->campaignThreads($id), 'name'));
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/threads', $id)], ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, ['name' => 'Find the ledger']);

            self::assertResponseStatusCodeSame(415);
            self::assertSame(['error' => 'Send the Thread as JSON.'], $this->json());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidThreads(): iterable
    {
        yield 'blank name' => [['name' => '  '], 'The name must not be blank.'];
        yield 'name of 201' => [['name' => str_repeat('a', 201)], 'The name must be at most 200 characters, got 201.'];
        yield 'an unknown tag' => [['name' => 'Rob the vault', 'tags' => ['ally', 'villain']], 'Tag "villain" not found in the pinned release.'];
        yield 'a duplicate tag' => [['name' => 'Rob the vault', 'tags' => ['ally', 'ally']], 'Tag "ally" is listed twice.'];
        yield 'eleven free tags' => [['name' => 'Rob the vault', 'freeTags' => array_map(static fn (int $i): string => 'free '.$i, range(1, 11))], 'At most 10 free tags are allowed, got 11.'];
        yield 'a blank free tag' => [['name' => 'Rob the vault', 'freeTags' => [' ']], 'A free tag must be 1 to 50 characters, got 0.'];
        yield 'weight 0' => [['name' => 'Rob the vault', 'weight' => 0], 'The weight must be 1 to 3, got 0.'];
        yield 'weight 4' => [['name' => 'Rob the vault', 'weight' => 4], 'The weight must be 1 to 3, got 4.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidThreads')]
    public function anInvalidThreadIsUnprocessableAndNothingChanges(array $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault', 'tags' => ['ally']]);
        $before = $this->campaignThreads($id);

        foreach ([['POST', \sprintf('/api/campaigns/%s/threads', $id)], ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId)]] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, $body);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => $error], $this->json());
        }

        self::assertSame($before, $this->campaignThreads($id));
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $bobsThread = $this->addThread($bobs, ['name' => 'Rob the vault']);
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([$bobs, self::UNKNOWN_ID, 'not-a-uuid'] as $id) {
            foreach ([
                ['POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Find the ledger']],
                ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $bobsThread), ['name' => 'Find the ledger']],
                ['POST', \sprintf('/api/campaigns/%s/threads/%s/close', $id, $bobsThread), null],
                ['POST', \sprintf('/api/campaigns/%s/threads/%s/reopen', $id, $bobsThread), null],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body ?? []);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
            }
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(threads) FROM play_campaign WHERE id = ?', [$bobs]));
    }

    #[Test]
    public function anUnknownOrMalformedThreadIdIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', ' '] as $threadId) {
            foreach ([
                ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, rawurlencode($threadId)), ['name' => 'Find the ledger']],
                ['POST', \sprintf('/api/campaigns/%s/threads/%s/close', $id, rawurlencode($threadId)), []],
                ['POST', \sprintf('/api/campaigns/%s/threads/%s/reopen', $id, rawurlencode($threadId)), []],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Thread "%s" not found.', $threadId)], $this->json());
            }
        }
    }

    #[Test]
    public function theCampaignIsCheckedBeforeTheThreadId(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $mine = $this->createCampaign();

        foreach ([$bobs, self::UNKNOWN_ID] as $campaignId) {
            foreach (['not-a-uuid', ' '] as $threadId) {
                foreach ([
                    ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $campaignId, rawurlencode($threadId)), ['name' => 'Find the ledger']],
                    ['POST', \sprintf('/api/campaigns/%s/threads/%s/close', $campaignId, rawurlencode($threadId)), []],
                    ['POST', \sprintf('/api/campaigns/%s/threads/%s/reopen', $campaignId, rawurlencode($threadId)), []],
                ] as [$method, $uri, $body]) {
                    $this->client->jsonRequest($method, $uri, $body);

                    self::assertResponseStatusCodeSame(404);
                    self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
                }
            }
        }

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads/not-a-uuid/close', $mine));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Thread "not-a-uuid" not found.'], $this->json());
    }

    #[Test]
    public function aGeneratedIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(ThreadIdGenerator::class, new readonly class implements ThreadIdGenerator {
            public function generate(): ThreadId
            {
                return ThreadId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->addThread($id, ['name' => 'Rob the vault']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Find the ledger']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Thread "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['Rob the vault'], array_column($this->campaignThreads($id), 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReleases(): iterable
    {
        yield 'release gone' => ['DELETE FROM studio_gamesystem_release'];
        yield 'unsupported schema version' => ['UPDATE studio_gamesystem_release SET schema_version = 99'];
    }

    #[Test]
    #[DataProvider('unreadableReleases')]
    public function aPinnedReleaseThatCannotBeReadIsAServerErrorWithoutInternals(string $breakTheRelease): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault']);
        $this->connection()->executeStatement($breakTheRelease);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/threads', $id)],
            ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId)],
        ] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['name' => 'Find the ledger']);

            self::assertResponseStatusCodeSame(500);
            self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(threads) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAnotherThread(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threads = array_map(static fn (int $i): array => ['id' => \sprintf('thread-%d', $i), 'name' => 'Thread '.$i, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 500));
        $this->connection()->executeStatement('UPDATE play_campaign SET threads = ? WHERE id = ?', [json_encode($threads, \JSON_THROW_ON_ERROR), $id]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'One too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 Threads.'], $this->json());
        self::assertCount(500, $this->campaignThreads($id));
    }

    #[Test]
    public function aChangeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id, ['name' => 'Rob the vault']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/threads', $id), ['name' => 'Find the ledger']],
            ['PUT', \sprintf('/api/campaigns/%s/threads/%s', $id, $threadId), ['name' => 'Renamed']],
            ['POST', \sprintf('/api/campaigns/%s/threads/%s/close', $id, $threadId), []],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$threadId, 'Rob the vault', 'open']], array_map(static fn (array $thread): array => [$thread['id'], $thread['name'], $thread['status']], $this->campaignThreads($id)));

        // Reopening an open Thread changes nothing, so it only conflicts once the Thread is closed.
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads/%s/close', $id, $threadId));
        self::assertResponseIsSuccessful();
        $this->requestWhileAnotherRequestSaves($id, 'POST', \sprintf('/api/campaigns/%s/threads/%s/reopen', $id, $threadId), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['closed'], array_column($this->campaignThreads($id), 'status'));
    }

    #[Test]
    public function threadEndpointsAreForSoloPlayersOnly(): void
    {
        $requests = [
            ['POST', '/api/campaigns/'.self::UNKNOWN_ID.'/threads', ['name' => 'Rob the vault']],
            ['PUT', \sprintf('/api/campaigns/%s/threads/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['name' => 'Rob the vault']],
            ['POST', \sprintf('/api/campaigns/%s/threads/%s/close', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
            ['POST', \sprintf('/api/campaigns/%s/threads/%s/reopen', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
        ];

        foreach ($requests as [$method, $uri, $body]) {
            $this->client->jsonRequest($method, $uri, $body);
            self::assertResponseStatusCodeSame(401);
        }

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            foreach ($requests as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(403);
                self::assertSame(['error' => 'Access denied.'], $this->json());
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWhileAnotherRequestSaves(string $campaignId, string $method, string $uri, array $body): void
    {
        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $campaignId) {
            public function __construct(private Connection $connection, private string $campaignId)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement('UPDATE play_campaign SET version = version + 1 WHERE id = ?', [$this->campaignId]);
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->client->jsonRequest($method, $uri, $body);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }
    }

    /**
     * @return list<array<string, mixed>> the Tags of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignTags(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $tags = $this->json()['tags'] ?? null;
        self::assertIsArray($tags);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($tags);

        return $list;
    }

    /**
     * @return list<array<string, mixed>> the Threads of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignThreads(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $threads = $this->json()['threads'] ?? null;
        self::assertIsArray($threads);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($threads);

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addThread(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'threads-npcs']);
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
