<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\PlaceIdGenerator;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignPlacesController;
use App\Play\Infrastructure\Http\PlaceResponse;
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
 * Campaign Places over the Play API, on the schema version 2 release "valid/v2-threads-npcs", whose
 * Tags are "leader", "ally" and "hostile"; none applies to Places yet.
 */
#[CoversClass(CampaignPlacesController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(PlaceResponse::class)]
final class CampaignPlacesApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with a string "name" and, optionally, a string or null "notes", lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Saltmarsh", "notes": "A fishing town", "weight": 2}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseViews::fixtureContent('valid/v2-threads-npcs'), false));
    }

    #[Test]
    public function aPlaceIsAddedWithItsDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => '  Saltmarsh ']);

        self::assertResponseStatusCodeSame(201);
        $place = $this->json();
        self::assertIsString($place['id'] ?? null);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $place['id']);
        self::assertSame(['name' => 'Saltmarsh', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open', 'notes' => null], array_diff_key($place, ['id' => 1]));
        self::assertSame([$place], $this->campaignPlaces($id));
    }

    #[Test]
    public function aPlaceIsAddedWithEveryField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'Saltmarsh', 'notes' => '  A fishing town ', 'freeTags' => ['Town'], 'weight' => 3]);

        self::assertResponseStatusCodeSame(201);
        $place = $this->json();
        self::assertSame(['Saltmarsh', 'A fishing town', [], ['Town'], 3, 'open'], [$place['name'] ?? null, $place['notes'] ?? null, $place['tags'] ?? null, $place['freeTags'] ?? null, $place['weight'] ?? null, $place['status'] ?? null]);
        self::assertSame([$place], $this->campaignPlaces($id));
    }

    #[Test]
    public function nullOptionalFieldsReadAsTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'Saltmarsh', 'notes' => null, 'tags' => null, 'freeTags' => null, 'weight' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['name' => 'Saltmarsh', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open', 'notes' => null], array_diff_key($this->json(), ['id' => 1]));
    }

    #[Test]
    public function anEditReplacesEveryFieldAndOmittedOnesTakeTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh', 'notes' => 'A fishing town', 'freeTags' => ['Town'], 'weight' => 3]);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, $placeId));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId), ['name' => 'Saltmarsh Keep', 'freeTags' => ['Ruin']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $placeId, 'name' => 'Saltmarsh Keep', 'tags' => [], 'freeTags' => ['Ruin'], 'weight' => 1, 'status' => 'closed', 'notes' => null], $this->json());
        self::assertSame([$this->json()], $this->campaignPlaces($id));
    }

    #[Test]
    public function closingAndReopeningAreIdempotentAndOpenPlacesListFirst(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $vault = $this->addPlace($id, ['name' => 'Saltmarsh']);
        $ledger = $this->addPlace($id, ['name' => 'Dunmere']);

        foreach (['close', 'close'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places/%s/%s', $id, $vault, $action));
            self::assertResponseIsSuccessful();
            self::assertSame(['Saltmarsh', 'closed'], [$this->json()['name'] ?? null, $this->json()['status'] ?? null]);
        }

        self::assertSame([[$ledger, 'open'], [$vault, 'closed']], array_map(static fn (array $place): array => [$place['id'], $place['status']], $this->campaignPlaces($id)));

        foreach (['reopen', 'reopen'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places/%s/%s', $id, $vault, $action));
            self::assertResponseIsSuccessful();
            self::assertSame('open', $this->json()['status'] ?? null);
        }

        self::assertSame([[$vault, 'open'], [$ledger, 'open']], array_map(static fn (array $place): array => [$place['id'], $place['status']], $this->campaignPlaces($id)));
    }

    #[Test]
    public function aReleaseTagIsRefusedAndAnUnknownOneIsToo(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/places', $id)], ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId)]] as [$method, $uri]) {
            // No release Tag applies to Places yet, so a Tag the release defines is refused...
            $this->client->jsonRequest($method, $uri, ['name' => 'Saltmarsh', 'tags' => ['ally']]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'Tag "ally" does not apply to Places.'], $this->json());

            // ...and one it does not define is unknown.
            $this->client->jsonRequest($method, $uri, ['name' => 'Saltmarsh', 'tags' => ['villain']]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'Tag "villain" not found in the pinned release.'], $this->json());
        }

        self::assertSame(['Saltmarsh'], array_column($this->campaignPlaces($id), 'name'));
        self::assertSame([[]], array_column($this->campaignPlaces($id), 'tags'));
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
        yield 'a numeric notes' => ['{"name": "Saltmarsh", "notes": 3}'];
        yield 'notes as a list' => ['{"name": "Saltmarsh", "notes": ["A town"]}'];
        yield 'tags as a string' => ['{"name": "Saltmarsh", "tags": "ally"}'];
        yield 'tags with a number' => ['{"name": "Saltmarsh", "tags": [3]}'];
        yield 'tags as an object' => ['{"name": "Saltmarsh", "tags": {"a": "ally"}}'];
        yield 'free tags as a string' => ['{"name": "Saltmarsh", "freeTags": "Fence"}'];
        yield 'free tags with a null' => ['{"name": "Saltmarsh", "freeTags": [null]}'];
        yield 'a string weight' => ['{"name": "Saltmarsh", "weight": "2"}'];
        yield 'a decimal weight' => ['{"name": "Saltmarsh", "weight": 2.5}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/places', $id)], ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => self::MALFORMED], $this->json());
        }

        self::assertSame(['Saltmarsh'], array_column($this->campaignPlaces($id), 'name'));
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/places', $id)], ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, ['name' => 'Dunmere']);

            self::assertResponseStatusCodeSame(415);
            self::assertSame(['error' => 'Send the Place as JSON.'], $this->json());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPlaces(): iterable
    {
        yield 'blank name' => [['name' => '  '], 'The name must not be blank.'];
        yield 'name of 201' => [['name' => str_repeat('a', 201)], 'The name must be at most 200 characters, got 201.'];
        yield 'notes of 2001' => [['name' => 'Saltmarsh', 'notes' => str_repeat('a', 2001)], 'The notes must be at most 2000 characters, got 2001.'];
        yield 'a duplicate tag' => [['name' => 'Saltmarsh', 'tags' => ['ally', 'ally']], 'Tag "ally" is listed twice.'];
        yield 'eleven free tags' => [['name' => 'Saltmarsh', 'freeTags' => array_map(static fn (int $i): string => 'free '.$i, range(1, 11))], 'At most 10 free tags are allowed, got 11.'];
        yield 'a blank free tag' => [['name' => 'Saltmarsh', 'freeTags' => [' ']], 'A free tag must be 1 to 50 characters, got 0.'];
        yield 'weight 0' => [['name' => 'Saltmarsh', 'weight' => 0], 'The weight must be 1 to 3, got 0.'];
        yield 'weight 4' => [['name' => 'Saltmarsh', 'weight' => 4], 'The weight must be 1 to 3, got 4.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidPlaces')]
    public function anInvalidPlaceIsUnprocessableAndNothingChanges(array $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh', 'freeTags' => ['Town']]);
        $before = $this->campaignPlaces($id);

        foreach ([['POST', \sprintf('/api/campaigns/%s/places', $id)], ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId)]] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, $body);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => $error], $this->json());
        }

        self::assertSame($before, $this->campaignPlaces($id));
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $bobsPlace = $this->addPlace($bobs, ['name' => 'Saltmarsh']);
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([$bobs, self::UNKNOWN_ID, 'not-a-uuid'] as $id) {
            foreach ([
                ['POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'Dunmere']],
                ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $bobsPlace), ['name' => 'Dunmere']],
                ['POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, $bobsPlace), null],
                ['POST', \sprintf('/api/campaigns/%s/places/%s/reopen', $id, $bobsPlace), null],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body ?? []);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
            }
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(places) FROM play_campaign WHERE id = ?', [$bobs]));
    }

    #[Test]
    public function anUnknownOrMalformedPlaceIdIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', ' '] as $placeId) {
            foreach ([
                ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, rawurlencode($placeId)), ['name' => 'Dunmere']],
                ['POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, rawurlencode($placeId)), []],
                ['POST', \sprintf('/api/campaigns/%s/places/%s/reopen', $id, rawurlencode($placeId)), []],
            ] as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Place "%s" not found.', $placeId)], $this->json());
            }
        }
    }

    #[Test]
    public function theCampaignIsCheckedBeforeThePlaceId(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $mine = $this->createCampaign();

        foreach ([$bobs, self::UNKNOWN_ID] as $campaignId) {
            foreach (['not-a-uuid', ' '] as $placeId) {
                foreach ([
                    ['PUT', \sprintf('/api/campaigns/%s/places/%s', $campaignId, rawurlencode($placeId)), ['name' => 'Dunmere']],
                    ['POST', \sprintf('/api/campaigns/%s/places/%s/close', $campaignId, rawurlencode($placeId)), []],
                    ['POST', \sprintf('/api/campaigns/%s/places/%s/reopen', $campaignId, rawurlencode($placeId)), []],
                ] as [$method, $uri, $body]) {
                    $this->client->jsonRequest($method, $uri, $body);

                    self::assertResponseStatusCodeSame(404);
                    self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
                }
            }
        }

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places/not-a-uuid/close', $mine));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Place "not-a-uuid" not found.'], $this->json());
    }

    #[Test]
    public function aGeneratedIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(PlaceIdGenerator::class, new readonly class implements PlaceIdGenerator {
            public function generate(): PlaceId
            {
                return PlaceId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->addPlace($id, ['name' => 'Saltmarsh']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'Dunmere']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Place "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['Saltmarsh'], array_column($this->campaignPlaces($id), 'name'));
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
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh']);
        $this->connection()->executeStatement($breakTheRelease);

        // Close and reopen save, then cannot build the response: they answer like add and edit.
        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/places', $id)],
            ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId)],
            ['POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, $placeId)],
            ['POST', \sprintf('/api/campaigns/%s/places/%s/reopen', $id, $placeId)],
        ] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['name' => 'Dunmere']);

            self::assertResponseStatusCodeSame(500);
            self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(places) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAnotherPlace(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $places = array_map(static fn (int $i): array => ['id' => \sprintf('place-%d', $i), 'name' => 'Place '.$i, 'notes' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 500));
        $this->connection()->executeStatement('UPDATE play_campaign SET places = ? WHERE id = ?', [json_encode($places, \JSON_THROW_ON_ERROR), $id]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'One too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 Places.'], $this->json());
        self::assertCount(500, $this->campaignPlaces($id));
    }

    #[Test]
    public function aChangeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $placeId = $this->addPlace($id, ['name' => 'Saltmarsh']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/places', $id), ['name' => 'Dunmere']],
            ['PUT', \sprintf('/api/campaigns/%s/places/%s', $id, $placeId), ['name' => 'Renamed']],
            ['POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, $placeId), []],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$placeId, 'Saltmarsh', 'open']], array_map(static fn (array $place): array => [$place['id'], $place['name'], $place['status']], $this->campaignPlaces($id)));

        // Reopening an open Place changes nothing, so it only conflicts once the Place is closed.
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places/%s/close', $id, $placeId));
        self::assertResponseIsSuccessful();
        $this->requestWhileAnotherRequestSaves($id, 'POST', \sprintf('/api/campaigns/%s/places/%s/reopen', $id, $placeId), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['closed'], array_column($this->campaignPlaces($id), 'status'));
    }

    #[Test]
    public function placeEndpointsAreForSoloPlayersOnly(): void
    {
        $requests = [
            ['POST', '/api/campaigns/'.self::UNKNOWN_ID.'/places', ['name' => 'Saltmarsh']],
            ['PUT', \sprintf('/api/campaigns/%s/places/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['name' => 'Saltmarsh']],
            ['POST', \sprintf('/api/campaigns/%s/places/%s/close', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
            ['POST', \sprintf('/api/campaigns/%s/places/%s/reopen', self::UNKNOWN_ID, self::UNKNOWN_ID), []],
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
     * @return list<array<string, mixed>> the Places of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignPlaces(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $places = $this->json()['places'] ?? null;
        self::assertIsArray($places);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($places);

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addPlace(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/places', $campaignId), $body);
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
