<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignReleaseUpgradeController;
use App\Play\Infrastructure\Http\ReleaseUpgradePlanResponse;
use App\Play\Infrastructure\Http\ReleaseUpgradeResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The release upgrade over the Play API, on the GameSystem "sheet" (the release "valid/v2-sheet"
 * without its Scene Types): release 1 has Tags "crew" and "leader" and Reflexes 1 to 10; the
 * upgrade target (release 2) caps Reflexes at 5 and drops the Tag "leader".
 */
#[CoversClass(CampaignReleaseUpgradeController::class)]
#[CoversClass(ReleaseUpgradeResponse::class)]
#[CoversClass(ReleaseUpgradePlanResponse::class)]
final class CampaignReleaseUpgradeApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with an integer "toVersion" and a string "fingerprint", such as {"toVersion": 3, "fingerprint": "9f2c4e0a..."}.';
    private const string CHANGED = 'The upgrade changed since the preview; review it again.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9101', $this->release());
    }

    #[Test]
    public function aCampaignOnTheLatestReleaseIsUpToDate(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', $id));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['upToDate' => true, 'currentVersion' => 1, 'plan' => null], $this->json());
    }

    #[Test]
    public function thePreviewListsWhatTheUpgradeChangesAndChangesNothing(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id);
        $npcId = $this->addNpc($id);
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', $id));

        self::assertResponseStatusCodeSame(200);
        $preview = $this->json();
        $plan = $preview['plan'] ?? null;
        self::assertIsArray($plan);
        self::assertSame([false, 1], [$preview['upToDate'] ?? null, $preview['currentVersion'] ?? null]);
        self::assertSame(['version' => 1, 'name' => 'Sheet'], $plan['from'] ?? null);
        self::assertSame(['version' => 2, 'name' => 'Sheet'], $plan['to'] ?? null);
        self::assertSame(
            [['id' => $characterId, 'name' => 'Vi', 'changes' => [['fieldKey' => 'reflexes', 'fieldName' => 'Reflexes', 'kind' => 'clamped', 'before' => 9, 'after' => 5]], 'droppedTags' => ['leader']]],
            $plan['characters'] ?? null,
        );
        self::assertSame([['id' => $npcId, 'name' => 'Mira', 'tags' => ['leader']]], $plan['npcs'] ?? null);
        self::assertSame([[], [], ['fromFlowKey' => null, 'toFlowKey' => null, 'restarted' => false]], [$plan['threads'] ?? null, $plan['trackers'] ?? null, $plan['flow'] ?? null]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->fingerprintOf($id));
        $campaign = $this->campaign($id);
        self::assertSame([1, 9, ['crew', 'leader']], [$campaign['pinnedRelease']['version'], $campaign['characters'][0]['values']['reflexes'], $campaign['characters'][0]['tags']]);
    }

    #[Test]
    public function theConfirmedUpgradeAppliesThePreviewAndAnswersTheCampaign(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->addCharacter($id);
        $this->addNpc($id);
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $fingerprint]);

        self::assertResponseStatusCodeSame(200);
        $campaign = $this->campaignJson();
        self::assertSame($id, $campaign['id']);
        self::assertSame(2, $campaign['pinnedRelease']['version']);
        self::assertSame([['reflexes' => 5, 'tags' => ['crew']]], array_map(static fn (array $character): array => ['reflexes' => $character['values']['reflexes'], 'tags' => $character['tags']], $campaign['characters']));
        self::assertSame([[]], array_column($campaign['npcs'], 'tags'));
        self::assertSame(['upToDate' => true, 'currentVersion' => 2, 'plan' => null], $this->preview($id));
    }

    #[Test]
    public function aSchemaVersionOneCampaignWithoutCharactersUpgradesToASchemaVersionTwoRelease(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9201', ReleaseViews::fixtureContent('valid/minimal'));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'Legacy', 'gameSystemKey' => 'minimal']);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9202', ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', ['fields' => [['key' => 'role', 'label' => 'Role', 'type' => 'text']]]));
        $plan = $this->previewedPlan($id);
        self::assertSame([[], [], [], [], [], ['fromFlowKey' => null, 'toFlowKey' => null, 'restarted' => false]], [$plan['characters'], $plan['npcs'], $plan['threads'], $plan['trackers'], $plan['facts'], $plan['flow']]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $plan['fingerprint']]);

        self::assertResponseStatusCodeSame(200);
        $campaign = $this->campaignJson();
        self::assertSame([2, ['role']], [$campaign['pinnedRelease']['version'], array_column($campaign['pinnedRelease']['sheet'], 'key')]);
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);

        foreach ([$id, self::UNKNOWN_ID, 'not-an-id'] as $campaignId) {
            $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', $campaignId));
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());

            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $campaignId), ['toVersion' => 2, 'fingerprint' => $fingerprint]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT release_version FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function theUpgradeIsForSoloPlayersOnly(): void
    {
        $uri = \sprintf('/api/campaigns/%s/release-upgrade', self::UNKNOWN_ID);

        foreach (['GET', 'POST'] as $method) {
            $this->client->jsonRequest($method, $uri);
            self::assertResponseStatusCodeSame(401);
        }

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            foreach (['GET', 'POST'] as $method) {
                $this->client->jsonRequest($method, $uri);

                self::assertResponseStatusCodeSame(403);
                self::assertSame(['error' => 'Access denied.'], $this->json());
            }
        }
    }

    #[Test]
    public function aVersionThatIsNotNewerThanThePinnedOneIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 1, 'fingerprint' => 'abc']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The campaign plays release 1; release 1 is not newer.'], $this->json());

        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $this->fingerprintOf($id)]);
        self::assertResponseStatusCodeSame(200);
        foreach ([2, 1] as $toVersion) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => $toVersion, 'fingerprint' => 'abc']);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('The campaign plays release 2; release %d is not newer.', $toVersion)], $this->json());
        }
    }

    #[Test]
    public function aPlanThatChangedSinceThePreviewIsAConflictAndNothingChanges(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id);
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['reflexes' => 3]]);
        self::assertResponseStatusCodeSame(200);

        foreach ([$fingerprint, 'not-the-fingerprint'] as $sent) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $sent]);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => self::CHANGED], $this->json());
        }

        $campaign = $this->campaign($id);
        self::assertSame([1, 3], [$campaign['pinnedRelease']['version'], $campaign['characters'][0]['values']['reflexes']]);
    }

    #[Test]
    public function aNewerReleasePublishedSinceThePreviewOrAnUnknownVersionIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9103', ReleaseArrays::with($this->target(), 'sheet.fields.2.max', 4));

        foreach ([2, 99] as $toVersion) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => $toVersion, 'fingerprint' => $fingerprint]);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => self::CHANGED], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT release_version FROM play_campaign WHERE id = ?', [$id]));
        self::assertSame(['version' => 3, 'name' => 'Sheet'], $this->previewedPlan($id)['to'] ?? null, 'The preview follows the latest release.');
    }

    #[Test]
    public function anUpgradeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);
        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $id) {
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
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $fingerprint]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        self::assertSame(1, $this->connection()->fetchOne('SELECT release_version FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aCampaignThatCannotBeReadAfterASavedUpgradeIsAServerErrorSayingTheUpgradeWasSaved(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());
        $fingerprint = $this->fingerprintOf($id);
        // Once the upgrade is committed, the releases vanish: the follow-up read of the campaign fails.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $releasesVanish = new readonly class($this->connection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function postFlush(): void
            {
                $this->connection->executeStatement('DELETE FROM studio_gamesystem_release');
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::postFlush], $releasesVanish);

        try {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => $fingerprint]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::postFlush], $releasesVanish);
        }

        self::assertResponseStatusCodeSame(500);
        self::assertSame(['error' => 'The upgrade was saved, but the campaign cannot be read now. Reload the page.'], $this->json());
        self::assertSame(2, $this->connection()->fetchOne('SELECT release_version FROM play_campaign WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badBodies(): iterable
    {
        yield 'not JSON' => ['{', 400];
        yield 'a list' => ['[]', 400];
        yield 'no version' => ['{"fingerprint": "abc"}', 400];
        yield 'no fingerprint' => ['{"toVersion": 2}', 400];
        yield 'a version that is a string' => ['{"toVersion": "2", "fingerprint": "abc"}', 400];
        yield 'a version that is a float' => ['{"toVersion": 2.5, "fingerprint": "abc"}', 400];
        yield 'a version that is null' => ['{"toVersion": null, "fingerprint": "abc"}', 400];
        yield 'a fingerprint that is a number' => ['{"toVersion": 2, "fingerprint": 7}', 400];
        yield 'a version of zero' => ['{"toVersion": 0, "fingerprint": "abc"}', 422];
        yield 'a negative version' => ['{"toVersion": -1, "fingerprint": "abc"}', 422];
        yield 'a blank fingerprint' => ['{"toVersion": 2, "fingerprint": "  "}', 422];
    }

    #[Test]
    #[DataProvider('badBodies')]
    public function aBadBodyIsRefusedAndNothingChanges(string $body, int $status): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $this->target());

        $this->client->request('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame(400 === $status ? self::MALFORMED : 'The "toVersion" must be positive and the "fingerprint" not blank.', $this->json()['error'] ?? null);
        self::assertSame(1, $this->connection()->fetchOne('SELECT release_version FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), server: ['CONTENT_TYPE' => 'text/plain'], content: 'toVersion=2');

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the upgrade as JSON.'], $this->json());
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
        $this->connection()->executeStatement($breakTheRelease);

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', $id));
        self::assertResponseStatusCodeSame(500);
        self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', $id), ['toVersion' => 2, 'fingerprint' => 'abc']);
        self::assertResponseStatusCodeSame(500);
        self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
    }

    /**
     * @return array<mixed> release 1
     */
    private function release(): array
    {
        return ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
    }

    /**
     * @return array<mixed> release 2: Reflexes up to 5 and no Tag "leader"
     */
    private function target(): array
    {
        return ReleaseArrays::with(ReleaseArrays::with($this->release(), 'sheet.fields.2.max', 5), 'tags', [['key' => 'crew', 'label' => 'Crew']]);
    }

    /**
     * @param array<mixed> $content
     */
    private function publish(string $releaseId, array $content): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease($releaseId, $content, false));
    }

    /**
     * @return array<mixed>
     */
    private function preview(string $id): array
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', $id));
        self::assertResponseStatusCodeSame(200);

        return $this->json();
    }

    /**
     * @return array<mixed>
     */
    private function previewedPlan(string $id): array
    {
        $plan = $this->preview($id)['plan'] ?? null;
        self::assertIsArray($plan);

        return $plan;
    }

    private function fingerprintOf(string $id): string
    {
        $fingerprint = $this->previewedPlan($id)['fingerprint'] ?? null;
        self::assertIsString($fingerprint);

        return $fingerprint;
    }

    /**
     * @return array{id: string, pinnedRelease: array{version: int, sheet: list<array{key: string}>}, characters: list<array{values: array{reflexes: int}, tags: list<string>}>, npcs: list<array{tags: list<string>}>}
     */
    private function campaign(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);

        return $this->campaignJson();
    }

    /**
     * @return array{id: string, pinnedRelease: array{version: int, sheet: list<array{key: string}>}, characters: list<array{values: array{reflexes: int}, tags: list<string>}>, npcs: list<array{tags: list<string>}>}
     */
    private function campaignJson(): array
    {
        /** @var array{id: string, pinnedRelease: array{version: int, sheet: list<array{key: string}>}, characters: list<array{values: array{reflexes: int}, tags: list<string>}>, npcs: list<array{tags: list<string>}>} $campaign */
        $campaign = $this->json();

        return $campaign;
    }

    private function addCharacter(string $campaignId): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $campaignId), ['name' => 'Vi', 'tags' => ['crew', 'leader'], 'values' => ['reflexes' => 9]]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function addNpc(string $campaignId): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $campaignId), ['name' => 'Mira', 'tags' => ['leader']]);
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
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'sheet']);
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
