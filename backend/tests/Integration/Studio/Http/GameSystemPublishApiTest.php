<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Infrastructure\Http\GameSystemReleaseController;
use App\Studio\Infrastructure\Http\GameSystemReleaseResponse;
use App\Studio\Infrastructure\Http\GameSystemReleaseSummaryResponse;
use App\Studio\Infrastructure\Http\PublishGameSystemRequest;
use App\Studio\Infrastructure\Http\RestoreGameSystemRequest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Publishing a GameSystem draft, its release history and restore over the Studio API. Game managers
 * only, and only the owner's draft: another user's key is not found, never forbidden.
 */
#[CoversClass(GameSystemReleaseController::class)]
#[CoversClass(GameSystemReleaseResponse::class)]
#[CoversClass(GameSystemReleaseSummaryResponse::class)]
#[CoversClass(PublishGameSystemRequest::class)]
#[CoversClass(RestoreGameSystemRequest::class)]
final class GameSystemPublishApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'history' => ['GET', self::BASE.'/mine/releases'];
        yield 'publish' => ['POST', self::BASE.'/mine/releases'];
        yield 'release' => ['GET', self::BASE.'/mine/releases/1'];
        yield 'restore' => ['POST', self::BASE.'/mine/restore'];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function noSessionIsUnauthorizedAndOtherRolesAreForbidden(string $method, string $path): void
    {
        $this->client->jsonRequest($method, $path, ['version' => 1]);
        self::assertResponseStatusCodeSame(401);

        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);
            $this->client->jsonRequest($method, $path, ['version' => 1]);
            self::assertResponseStatusCodeSame(403, $role);
        }
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function anUnknownKeyOrAnotherOwnersKeyIsNotFound(string $method, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['mine', 'nothing'] as $key) {
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['version' => 1]);

            self::assertResponseStatusCodeSame(404, $key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->client->request('GET', self::BASE.'/mine/releases');
        self::assertCount(1, $this->json());
    }

    #[Test]
    public function aDraftIsPublishedWithNotesAndShowsInTheHistory(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => 'First cut.']);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location', self::BASE.'/mine/releases/1');
        $created = $this->json();
        self::assertSame([1, 2, 'First cut.', 'mine'], [$created['version'], $created['schemaVersion'], $created['notes'], $created['name']]);
        self::assertIsString($created['contentHash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $created['contentHash']);
        self::assertIsString($created['publishedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $created['publishedAt']);
        self::assertSame(array_fill_keys(['oracleTables', 'likelihoodOracles', 'trackers', 'factSlots', 'tags', 'sceneTypes', 'flows', 'sheetFields', 'checks'], 0), $created['sectionCounts']);

        $this->client->request('GET', self::BASE.'/mine/releases/1');
        self::assertResponseIsSuccessful();
        self::assertSame($created, $this->json());

        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame([1, false], [$this->json()['latestReleaseVersion'], $this->json()['hasUnpublishedChanges']]);
    }

    #[Test]
    public function theBodyMayBeLeftOutOrEmptyAndTheHistoryIsNewestFirst(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->request('GET', self::BASE.'/mine/releases');
        self::assertSame([], $this->json());

        $this->client->request('POST', self::BASE.'/mine/releases');
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['notes']);
        $this->rename('mine', 'Renamed');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->json()['version']);
        $this->rename('mine', 'Renamed again');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => null]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', self::BASE.'/mine/releases');

        $history = $this->json();
        self::assertSame([3, 2, 1], array_column($history, 'version'));
        self::assertIsArray($history[0]);
        self::assertSame(['version', 'schemaVersion', 'publishedAt', 'notes', 'contentHash', 'librarySources'], array_keys($history[0]));
        self::assertSame([null, null, null], array_column($history, 'librarySources'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function malformedPublishes(): iterable
    {
        yield 'notes not a string' => ['{"notes": 5}', 400];
        yield 'notes a list' => ['{"notes": ["x"]}', 400];
        yield 'body a list' => ['["x"]', 400];
        yield 'body not JSON' => ['{"notes": "x"', 400];
    }

    #[Test]
    #[DataProvider('malformedPublishes')]
    public function aMalformedPublishIsABadRequestAndPublishesNothing(string $content, int $status): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->request('POST', self::BASE.'/mine/releases', server: ['CONTENT_TYPE' => 'application/json'], content: $content);

        self::assertResponseStatusCodeSame($status);
        self::assertArrayHasKey('error', $this->json());
        $this->assertNoReleases('mine');
    }

    #[Test]
    public function aBodyThatIsNotJsonIsRefused(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->request('POST', self::BASE.'/mine/releases', server: ['CONTENT_TYPE' => 'text/plain'], content: 'notes=x');
        self::assertResponseStatusCodeSame(415);

        $this->client->request('POST', self::BASE.'/mine/restore', server: ['CONTENT_TYPE' => 'text/plain'], content: 'version=1');
        self::assertResponseStatusCodeSame(415);
        $this->assertNoReleases('mine');
    }

    #[Test]
    public function blankOrTooLongNotesAreUnprocessableAndPublishNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        foreach (['  ' => 'blank', "\u{00A0}\f" => 'blank', "\u{200B}\u{FEFF}" => 'blank', "a\0b" => 'NUL', str_repeat('é', 2001) => '2000 characters'] as $notes => $mentions) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => $notes]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString($mentions, $this->error());
        }

        $this->assertNoReleases('mine');
    }

    #[Test]
    public function anInvalidDraftIsUnprocessableAndPublishesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->replaceDocument('mine', ['schemaVersion' => 2, 'gameSystem' => ['key' => 'mine', 'name' => 'Mine']]);

        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => 'Broken.']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('oracles: ', $this->error());
        $this->assertNoReleases('mine');
    }

    #[Test]
    public function aDraftEqualToTheLatestReleaseIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);

        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => 'Again.']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('No changes', $this->error());
        $this->client->request('GET', self::BASE.'/mine/releases');
        self::assertCount(1, $this->json());
    }

    #[Test]
    public function aVersionTakenByAConcurrentPublishIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        // Another publish of this key takes version 1 after this one chose it, right before it flushes.
        $this->duringRequest(
            static fn (Connection $connection): int|string => $connection->executeStatement(
                "INSERT INTO studio_gamesystem_release (id, game_system_key, version, schema_version, content, content_hash, published_at) VALUES ('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', 'mine', 1, 2, '{}', :hash, NOW())",
                ['hash' => str_repeat('a', 64)],
            ),
            fn (): \Symfony\Component\DomCrawler\Crawler => $this->client->jsonRequest('POST', self::BASE.'/mine/releases', ['notes' => 'Racing.']),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('mine v1 already exists', $this->error());
    }

    #[Test]
    public function aReleaseViewOfAnUnknownVersionIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        foreach (['1', '0', 'latest', '99999999999999999999'] as $version) {
            $this->client->request('GET', self::BASE.'/mine/releases/'.$version);

            self::assertResponseStatusCodeSame(404, $version);
        }

        self::assertSame('No release mine v1.', $this->errorOf('GET', self::BASE.'/mine/releases/1'));
    }

    #[Test]
    public function aRestoreReplacesTheDraftWithAReleaseAndLeavesNothingUnpublished(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        $this->rename('mine', 'Renamed');

        $this->client->jsonRequest('POST', self::BASE.'/mine/restore', ['version' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSame(['mine', 'mine', 1, false], [$this->json()['key'], $this->json()['name'], $this->json()['latestReleaseVersion'], $this->json()['hasUnpublishedChanges']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedRestores(): iterable
    {
        yield 'no version' => [[]];
        yield 'version a string' => [['version' => '1']];
        yield 'version a float' => [['version' => 1.5]];
        yield 'version null' => [['version' => null]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedRestores')]
    public function aMalformedRestoreIsABadRequestAndChangesNothing(array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        $this->rename('mine', 'Renamed');

        $this->client->jsonRequest('POST', self::BASE.'/mine/restore', $body);

        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('Renamed', $this->json()['name']);
    }

    #[Test]
    public function restoringAnUnknownVersionIsUnprocessableAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->rename('mine', 'Renamed');

        foreach ([1, 0, -3] as $version) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/restore', ['version' => $version]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('No release mine', $this->error());
        }

        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('Renamed', $this->json()['name']);
    }

    #[Test]
    public function aDraftSavedByAnotherRequestMeanwhileMakesARestoreAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        $this->rename('mine', 'Renamed');

        $this->duringRequest(
            static fn (Connection $connection): int|string => $connection->executeStatement("UPDATE studio_gamesystem SET version = version + 1 WHERE game_system_key = 'mine'"),
            fn (): \Symfony\Component\DomCrawler\Crawler => $this->client->jsonRequest('POST', self::BASE.'/mine/restore', ['version' => 1]),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('mine', $this->error());
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('Renamed', $this->json()['name']);
    }

    /**
     * Runs the request with another request's write happening right before the first flush.
     *
     * @param \Closure(Connection): mixed $otherRequest
     * @param \Closure(): mixed           $request
     */
    private function duringRequest(\Closure $otherRequest, \Closure $request): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($entityManager->getConnection(), $otherRequest) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly \Closure $write)
            {
            }

            public function preFlush(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    ($this->write)($this->connection);
                }
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $listener);

        try {
            $request();
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $listener);
        }
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles, bool $create = true): void
    {
        if ($create) {
            $container = self::getContainer();
            $id = $container->get(UserIdGenerator::class)->generate()->toString();
            $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));
        }

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    private function createDraft(string $key): void
    {
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key]);
        self::assertResponseStatusCodeSame(201);
    }

    private function rename(string $key, string $name): void
    {
        $this->client->jsonRequest('PUT', self::BASE.'/'.$key.'/metadata', ['name' => $name]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $document
     */
    private function replaceDocument(string $key, array $document): void
    {
        $repository = self::getContainer()->get(GameSystemRepository::class);
        $draft = $repository->ofKey($key);
        self::assertNotNull($draft);
        $draft->restore($document, new \DateTimeImmutable());
        $repository->save($draft);
    }

    private function assertNoReleases(string $key): void
    {
        $this->client->request('GET', self::BASE.'/'.$key.'/releases');
        self::assertSame([], $this->json());
    }

    private function errorOf(string $method, string $path): string
    {
        $this->client->request($method, $path);

        return $this->error();
    }

    private function error(): string
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);

        return $error;
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
