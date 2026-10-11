<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\NpcIdGenerator;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignNpcsController;
use App\Play\Infrastructure\Http\NpcResponse;
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
 * Campaign NPCs over the Play API, on the schema version 2 release "valid/v2-threads-npcs", whose
 * Tags are "leader" (Leader, with a hint), "ally" (Ally) and "hostile" (Hostile, with a hint).
 */
#[CoversClass(CampaignNpcsController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(NpcResponse::class)]
#[CoversClass(ThreadResponse::class)]
#[CoversClass(TagResponse::class)]
final class CampaignNpcsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with a string "name" and, optionally, a string or null "agenda", lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Mira", "tags": ["ally"], "weight": 2}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseViews::fixtureContent('valid/v2-threads-npcs'), false));
    }

    #[Test]
    public function aNewCampaignShowsTheReleaseTagsAndNoNpcsOrThreads(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame([
            ['key' => 'leader', 'label' => 'Leader', 'hint' => 'Runs a faction or a crew.'],
            ['key' => 'ally', 'label' => 'Ally', 'hint' => null],
            ['key' => 'hostile', 'label' => 'Hostile', 'hint' => 'Works against the player.'],
        ], $campaign['tags'] ?? null);
        self::assertSame([[], []], [$campaign['npcs'] ?? null, $campaign['threads'] ?? null]);
    }

    #[Test]
    public function anNpcIsAddedWithItsDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => '  Mira ']);

        self::assertResponseStatusCodeSame(201);
        $npc = $this->json();
        self::assertIsString($npc['id'] ?? null);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $npc['id']);
        self::assertSame(['name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], array_diff_key($npc, ['id' => 1]));
        self::assertSame([$npc], $this->campaignNpcs($id));
    }

    #[Test]
    public function anNpcIsAddedWithEveryField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['ally', 'leader'], 'freeTags' => ['Fence'], 'weight' => 3]);

        self::assertResponseStatusCodeSame(201);
        $npc = $this->json();
        self::assertSame(['Mira', 'Wants the ledger', ['ally', 'leader'], ['Fence'], 3, 'open'], [$npc['name'] ?? null, $npc['agenda'] ?? null, $npc['tags'] ?? null, $npc['freeTags'] ?? null, $npc['weight'] ?? null, $npc['status'] ?? null]);
        self::assertSame([$npc], $this->campaignNpcs($id));
    }

    #[Test]
    public function nullOptionalFieldsReadAsTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Mira', 'agenda' => null, 'tags' => null, 'freeTags' => null, 'weight' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], array_diff_key($this->json(), ['id' => 1]));
    }

    #[Test]
    public function anEditReplacesEveryFieldAndOmittedOnesTakeTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['ally'], 'freeTags' => ['Fence'], 'weight' => 3]);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $npcId));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId), ['name' => 'Mira Vane', 'tags' => ['hostile']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $npcId, 'name' => 'Mira Vane', 'agenda' => null, 'tags' => ['hostile'], 'freeTags' => [], 'weight' => 1, 'status' => 'closed'], $this->json());
        self::assertSame([$this->json()], $this->campaignNpcs($id));
    }

    #[Test]
    public function closingAndReopeningAreIdempotentAndOpenNpcsListFirst(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $mira = $this->addNpc($id, ['name' => 'Mira']);
        $brann = $this->addNpc($id, ['name' => 'Brann']);

        foreach (['close', 'close'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/%s', $id, $mira, $action));
            self::assertResponseIsSuccessful();
            self::assertSame(['Mira', 'closed'], [$this->json()['name'] ?? null, $this->json()['status'] ?? null]);
        }

        self::assertSame([[$brann, 'open'], [$mira, 'closed']], array_map(static fn (array $npc): array => [$npc['id'], $npc['status']], $this->campaignNpcs($id)));

        foreach (['reopen', 'reopen'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/%s', $id, $mira, $action));
            self::assertResponseIsSuccessful();
            self::assertSame('open', $this->json()['status'] ?? null);
        }

        self::assertSame([[$mira, 'open'], [$brann, 'open']], array_map(static fn (array $npc): array => [$npc['id'], $npc['status']], $this->campaignNpcs($id)));
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

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Mira', 'tags' => ['newcomer']]);

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
        yield 'a numeric agenda' => ['{"name": "Mira", "agenda": 4}'];
        yield 'tags as a string' => ['{"name": "Mira", "tags": "ally"}'];
        yield 'tags with a number' => ['{"name": "Mira", "tags": [3]}'];
        yield 'tags as an object' => ['{"name": "Mira", "tags": {"a": "ally"}}'];
        yield 'free tags as a string' => ['{"name": "Mira", "freeTags": "Fence"}'];
        yield 'free tags with a null' => ['{"name": "Mira", "freeTags": [null]}'];
        yield 'a string weight' => ['{"name": "Mira", "weight": "2"}'];
        yield 'a decimal weight' => ['{"name": "Mira", "weight": 2.5}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/npcs', $id)], ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => self::MALFORMED], $this->json());
        }

        self::assertSame(['Mira'], array_column($this->campaignNpcs($id), 'name'));
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/npcs', $id)], ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, ['name' => 'Brann']);

            self::assertResponseStatusCodeSame(415);
            self::assertSame(['error' => 'Send the NPC as JSON.'], $this->json());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidNpcs(): iterable
    {
        yield 'blank name' => [['name' => '  '], 'The name must not be blank.'];
        yield 'name of 101' => [['name' => str_repeat('a', 101)], 'The name must be at most 100 characters, got 101.'];
        yield 'agenda of 2001' => [['name' => 'Mira', 'agenda' => str_repeat('a', 2001)], 'The agenda must be at most 2000 characters, got 2001.'];
        yield 'an unknown tag' => [['name' => 'Mira', 'tags' => ['ally', 'villain']], 'Tag "villain" not found in the pinned release.'];
        yield 'a duplicate tag' => [['name' => 'Mira', 'tags' => ['ally', 'ally']], 'Tag "ally" is listed twice.'];
        yield 'eleven free tags' => [['name' => 'Mira', 'freeTags' => array_map(static fn (int $i): string => 'free '.$i, range(1, 11))], 'At most 10 free tags are allowed, got 11.'];
        yield 'a blank free tag' => [['name' => 'Mira', 'freeTags' => [' ']], 'A free tag must be 1 to 50 characters, got 0.'];
        yield 'weight 0' => [['name' => 'Mira', 'weight' => 0], 'The weight must be 1 to 3, got 0.'];
        yield 'weight 4' => [['name' => 'Mira', 'weight' => 4], 'The weight must be 1 to 3, got 4.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidNpcs')]
    public function anInvalidNpcIsUnprocessableAndNothingChanges(array $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira', 'tags' => ['ally']]);
        $before = $this->campaignNpcs($id);

        foreach ([['POST', \sprintf('/api/campaigns/%s/npcs', $id)], ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId)]] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, $body);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => $error], $this->json());
        }

        self::assertSame($before, $this->campaignNpcs($id));
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $bobsNpc = $this->addNpc($bobs, ['name' => 'Mira']);
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([$bobs, self::UNKNOWN_ID, 'not-a-uuid'] as $id) {
            foreach ([
                ['POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Brann']],
                ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $bobsNpc), ['name' => 'Brann']],
                ['POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $bobsNpc), null],
                ['POST', \sprintf('/api/campaigns/%s/npcs/%s/reopen', $id, $bobsNpc), null],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body ?? []);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
            }
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(npcs) FROM play_campaign WHERE id = ?', [$bobs]));
    }

    #[Test]
    public function anUnknownOrMalformedNpcIdIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', ' '] as $npcId) {
            foreach ([
                ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, rawurlencode($npcId)), ['name' => 'Brann']],
                ['POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, rawurlencode($npcId)), []],
                ['POST', \sprintf('/api/campaigns/%s/npcs/%s/reopen', $id, rawurlencode($npcId)), []],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('NPC "%s" not found.', $npcId)], $this->json());
            }
        }
    }

    #[Test]
    public function theCampaignIsCheckedBeforeTheNpcId(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $mine = $this->createCampaign();

        foreach ([$bobs, self::UNKNOWN_ID] as $campaignId) {
            foreach (['not-a-uuid', ' '] as $npcId) {
                foreach ([
                    ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $campaignId, rawurlencode($npcId)), ['name' => 'Brann']],
                    ['POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $campaignId, rawurlencode($npcId)), []],
                    ['POST', \sprintf('/api/campaigns/%s/npcs/%s/reopen', $campaignId, rawurlencode($npcId)), []],
                ] as [$method, $uri, $body]) {
                    $this->client->jsonRequest($method, $uri, $body);

                    self::assertResponseStatusCodeSame(404);
                    self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
                }
            }
        }

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/not-a-uuid/close', $mine));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'NPC "not-a-uuid" not found.'], $this->json());
    }

    #[Test]
    public function aGeneratedIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(NpcIdGenerator::class, new readonly class implements NpcIdGenerator {
            public function generate(): NpcId
            {
                return NpcId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->addNpc($id, ['name' => 'Mira']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Brann']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'NPC "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['Mira'], array_column($this->campaignNpcs($id), 'name'));
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
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $this->connection()->executeStatement($breakTheRelease);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/npcs', $id)],
            ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId)],
        ] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['name' => 'Brann']);

            self::assertResponseStatusCodeSame(500);
            self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(npcs) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAnotherNpc(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcs = array_map(static fn (int $i): array => ['id' => \sprintf('npc-%d', $i), 'name' => 'NPC '.$i, 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 500));
        $this->connection()->executeStatement('UPDATE play_campaign SET npcs = ? WHERE id = ?', [json_encode($npcs, \JSON_THROW_ON_ERROR), $id]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'One too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 NPCs.'], $this->json());
        self::assertCount(500, $this->campaignNpcs($id));
    }

    #[Test]
    public function aChangeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Brann']],
            ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId), ['name' => 'Renamed']],
            ['POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $npcId), []],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$npcId, 'Mira', 'open']], array_map(static fn (array $npc): array => [$npc['id'], $npc['name'], $npc['status']], $this->campaignNpcs($id)));

        // Reopening an open NPC changes nothing, so it only conflicts once the NPC is closed.
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $npcId));
        self::assertResponseIsSuccessful();
        $this->requestWhileAnotherRequestSaves($id, 'POST', \sprintf('/api/campaigns/%s/npcs/%s/reopen', $id, $npcId), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['closed'], array_column($this->campaignNpcs($id), 'status'));
    }

    #[Test]
    public function npcEndpointsAreForSoloPlayersOnly(): void
    {
        $requests = [
            ['POST', '/api/campaigns/'.self::UNKNOWN_ID.'/npcs', ['name' => 'Mira']],
            ['PUT', \sprintf('/api/campaigns/%s/npcs/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['name' => 'Mira']],
            ['POST', \sprintf('/api/campaigns/%s/npcs/%s/close', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
            ['POST', \sprintf('/api/campaigns/%s/npcs/%s/reopen', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
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
     * @return list<array<string, mixed>> the NPCs of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignNpcs(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $npcs = $this->json()['npcs'] ?? null;
        self::assertIsArray($npcs);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($npcs);

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addNpc(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $campaignId), $body);
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
