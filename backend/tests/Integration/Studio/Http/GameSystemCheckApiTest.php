<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\CheckFieldResponse;
use App\Studio\Infrastructure\Http\CheckTrackerResponse;
use App\Studio\Infrastructure\Http\GameSystemCheckController;
use App\Studio\Infrastructure\Http\GameSystemChecksResponse;
use App\Studio\Infrastructure\Http\OracleInUseResponse;
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
 * The checks of a GameSystem draft over the Studio API: the read and the create, replace, delete and
 * move commands. Owner only: another user's draft is not found. Runs against valid/v2-checks: the
 * check "skill" is named by the check step sceneTypes[0].play[0], whose outcomes name the bands
 * "fail" and "crit"; the check "luck" is named by nothing.
 */
#[CoversClass(GameSystemCheckController::class)]
#[CoversClass(GameSystemChecksResponse::class)]
#[CoversClass(CheckFieldResponse::class)]
#[CoversClass(CheckTrackerResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
final class GameSystemCheckApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const array ROUTES = [
        'read' => ['GET', '/mine/checks'],
        'create' => ['POST', '/mine/checks'],
        'replace' => ['PUT', '/mine/checks/luck'],
        'delete' => ['DELETE', '/mine/checks/luck?expectedVersion=1'],
        'move' => ['POST', '/mine/checks/luck/move'],
    ];

    private KernelBrowser $client;
    private int $releases = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        foreach (self::ROUTES as $name => [$method, $path]) {
            yield $name => [$method, self::BASE.$path];
        }
    }

    #[Test]
    #[DataProvider('routes')]
    public function noSessionIsUnauthorized(string $method, string $path): void
    {
        $this->client->jsonRequest($method, $path, []);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    #[DataProvider('routes')]
    public function onlyAGameManagerEntersStudio(string $method, string $path): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $this->client->jsonRequest($method, $path, []);
        self::assertResponseStatusCodeSame(403);

        $this->signIn('olga@example.com', ['OWNER']);
        $this->client->jsonRequest($method, $path, []);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    #[DataProvider('routes')]
    public function anotherOwnersOrAMissingDraftIsNotFound(string $method, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['mine', 'nothing'] as $key) {
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'check' => $this->grit(), 'index' => 0]);

            self::assertResponseStatusCodeSame(404, $method.' '.$key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        self::assertSame(1, $this->checks()['version'], 'Nothing changed.');
    }

    #[Test]
    public function theChecksAreReadWithTheirCatalogAndUsage(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('GET', self::BASE.'/mine/checks');

        self::assertResponseIsSuccessful();
        $checks = $this->json();
        self::assertSame([1, 2], [$checks['version'], $checks['schemaVersion']]);
        self::assertSame(['skill', 'luck'], $this->keys($checks));
        self::assertSame('{check:difficulty}-1', self::at($checks, 'checks.0.bands.0.upTo.formula'));
        self::assertSame([['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'max' => null], ['key' => 'edge', 'label' => 'Edge', 'type' => 'derived', 'max' => null]], $checks['fields']);
        self::assertSame([['key' => 'alarm', 'label' => 'Alarm', 'initial' => 0]], $checks['trackers']);
        self::assertSame(['skill' => ['sceneTypes[0].play[0].check'], 'luck' => []], $checks['usages']);
    }

    #[Test]
    public function aVersion1DraftReadsWithoutChecksAndRefusesEveryCommand(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $before = $this->checks('journal');
        self::assertSame([1, []], [$before['schemaVersion'], $before['checks']]);
        self::assertSame([], $before['usages']);

        foreach ([
            ['POST', '/journal/checks', ['expectedVersion' => 1, 'check' => $this->grit()]],
            ['PUT', '/journal/checks/skill', ['expectedVersion' => 1, 'check' => $this->grit()]],
            ['DELETE', '/journal/checks/skill?expectedVersion=1', []],
            ['POST', '/journal/checks/skill/move', ['expectedVersion' => 1, 'index' => 0]],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            // The version 1 refusal comes before the lookup of the check, so even a key that names nothing is a 422, not a 404.
            self::assertResponseStatusCodeSame(422, $method.' '.$path);
            self::assertStringContainsString('schemaVersion', $this->error(), $method.' '.$path);
        }

        $this->client->jsonRequest('POST', self::BASE.'/journal/checks', ['expectedVersion' => 1, 'check' => $this->grit()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->checks('journal'));
    }

    #[Test]
    public function aCheckIsCreatedAndAppended(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => 1, 'check' => $this->grit()]);

        self::assertResponseStatusCodeSame(201);
        $checks = $this->json();
        self::assertSame(2, $checks['version']);
        self::assertSame(['skill', 'luck', 'grit'], $this->keys($checks));
        self::assertSame([], self::at($checks, 'usages.grit'));
        // The database stores the document as jsonb, which does not keep the order of an object's keys.
        self::assertEquals($checks, $this->checks());
    }

    #[Test]
    public function aCheckIsReplacedAndRenamedInPlaceAndTheRestIsKept(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/luck', ['expectedVersion' => 1, 'check' => [...(array) self::at($before, 'checks.1'), 'key' => 'fortune', 'label' => 'Fortune']]);

        self::assertResponseIsSuccessful();
        $checks = $this->json();
        self::assertSame(2, $checks['version']);
        self::assertSame(['skill', 'fortune'], $this->keys($checks));
        self::assertSame('Fortune', self::at($checks, 'checks.1.label'));
        self::assertEquals(self::at($before, 'checks.0'), self::at($checks, 'checks.0'));

        $skill = (array) self::at($checks, 'checks.0');
        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/skill', ['expectedVersion' => 2, 'check' => [...$skill, 'dice' => '2d6']]);
        self::assertResponseIsSuccessful();
        self::assertSame('2d6', self::at($this->json(), 'checks.0.dice'));
    }

    #[Test]
    public function anUnreferencedCheckIsDeletedWithTheVersionInTheQuery(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach (['', '?expectedVersion=', '?expectedVersion=one', '?expectedVersion=1.5', '?expectedVersion[]=1'] as $query) {
            $this->client->request('DELETE', self::BASE.'/mine/checks/luck'.$query);
            self::assertResponseStatusCodeSame(400, $query);
        }
        self::assertSame(1, $this->checks()['version']);

        $this->client->request('DELETE', self::BASE.'/mine/checks/luck?expectedVersion=1');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);
        self::assertSame(['skill'], $this->keys($this->json()));
        self::assertNull(self::at($this->json(), 'usages.luck'));
    }

    #[Test]
    public function aCheckIsMovedWithinTheWholeList(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/checks/luck/move', ['expectedVersion' => 1, 'index' => 0]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);
        self::assertSame(['luck', 'skill'], $this->keys($this->json()));
    }

    #[Test]
    public function aMoveOutOfRangeIsUnprocessableOnTheIndex(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        foreach ([2, -1, 99] as $index) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/checks/luck/move', ['expectedVersion' => 1, 'index' => $index]);

            self::assertResponseStatusCodeSame(422, (string) $index);
            self::assertStringStartsWith('index: ', $this->error());
        }

        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aReferencedCheckCannotBeRenamedOrDeletedAndTheErrorListsWhere(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $paths = ['sceneTypes[0].play[0].check'];

        $this->client->request('DELETE', self::BASE.'/mine/checks/skill?expectedVersion=1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);
        self::assertStringContainsString($paths[0], $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/skill', ['expectedVersion' => 1, 'check' => [...(array) self::at($before, 'checks.0'), 'key' => 'aptitude']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);

        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aCheckThatDropsAReferencedBandIsRefusedWithTheOutcomePaths(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $skill = (array) self::at($before, 'checks.0');
        $bands = (array) $skill['bands'];
        $bands[0] = [...(array) $bands[0], 'key' => 'failed'];

        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/skill', ['expectedVersion' => 1, 'check' => [...$skill, 'bands' => $bands]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sceneTypes[0].play[0].outcomes[0].band'], $this->json()['paths']);
        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aKeyAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => 1, 'check' => [...$this->grit(), 'key' => 'luck']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('The draft already has a check "luck".', $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/luck', ['expectedVersion' => 1, 'check' => [...$this->grit(), 'key' => 'skill']]);
        self::assertResponseStatusCodeSame(409);

        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function anUnknownCheckIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/nothing', ['expectedVersion' => 1, 'check' => $this->grit()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('The draft has no check "nothing".', $this->error());
        $this->client->request('DELETE', self::BASE.'/mine/checks/nothing?expectedVersion=1');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', self::BASE.'/mine/checks/nothing/move', ['expectedVersion' => 1, 'index' => 0]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aStaleVersionIsConflictingOnEveryWriteAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => 1, 'check' => $this->grit()]);
        $before = $this->checks();

        foreach ([
            ['POST', '/mine/checks', ['expectedVersion' => 1, 'check' => [...$this->grit(), 'key' => 'mana']]],
            ['PUT', '/mine/checks/grit', ['expectedVersion' => 1, 'check' => [...$this->grit(), 'label' => 'Changed']]],
            ['DELETE', '/mine/checks/grit?expectedVersion=1', []],
            ['POST', '/mine/checks/grit/move', ['expectedVersion' => 1, 'index' => 0]],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertNotSame('', $this->error());
        }
        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aConcurrentSaveIsAConflictOnEveryWriteAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Another request saves the draft between this request's load and its flush.
        $entityManager->getEventManager()->addEventListener(Events::onFlush, new readonly class($entityManager->getConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function onFlush(): void
            {
                $this->connection->executeStatement("UPDATE studio_gamesystem SET version = version + 1 WHERE game_system_key = 'mine'");
            }
        });

        foreach ([
            ['POST', '/mine/checks', ['check' => $this->grit()]],
            ['PUT', '/mine/checks/luck', ['check' => [...(array) self::at($before, 'checks.1'), 'label' => 'Changed']]],
            ['DELETE', '/mine/checks/luck?expectedVersion=%d', []],
            ['POST', '/mine/checks/luck/move', ['index' => 0]],
        ] as [$method, $path, $body]) {
            $version = self::at($this->checks(), 'version');
            self::assertIsInt($version);
            $this->client->jsonRequest($method, self::BASE.\sprintf($path, $version), [...$body, 'expectedVersion' => $version]);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertSame($before['checks'], $this->checks()['checks'], 'The refused save changed nothing but the version.');
        }
    }

    #[Test]
    public function aReadThatFailsAfterAWriteRollsTheWriteBack(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($entityManager) {
            public bool $armed = true;
            public int $fired = 0;

            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            /**
             * Once the write is flushed, corrupts the stored document and forgets the loaded draft, so that
             * the read after the write fails.
             */
            public function postFlush(): void
            {
                if ($this->armed) {
                    ++$this->fired;
                    $this->entityManager->getConnection()->executeStatement("UPDATE studio_gamesystem SET document = '\"corrupt\"'::jsonb WHERE game_system_key = 'mine'");
                    $this->entityManager->clear();
                }
            }
        };
        $entityManager->getEventManager()->addEventListener(Events::postFlush, $listener);

        $this->client->catchExceptions(false);
        $failed = false;
        try {
            $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => 1, 'check' => $this->grit()]);
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertGreaterThan(0, $listener->fired, 'The write was flushed before the read failed.');
        self::assertTrue($failed, 'The corrupted document makes the read fail.');
        self::assertSame($before, $this->checks(), 'The write was rolled back with the failed read, version included.');
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function malformedBodies(): iterable
    {
        $grit = self::grit();

        foreach (['create' => ['POST', '/mine/checks'], 'replace' => ['PUT', '/mine/checks/luck']] as $name => [$method, $path]) {
            yield $name.': no version' => [$method, $path, ['check' => $grit]];
            yield $name.': text version' => [$method, $path, ['expectedVersion' => '1', 'check' => $grit]];
            yield $name.': no check' => [$method, $path, ['expectedVersion' => 1]];
            yield $name.': null check' => [$method, $path, ['expectedVersion' => 1, 'check' => null]];
            yield $name.': text check' => [$method, $path, ['expectedVersion' => 1, 'check' => 'grit']];
            yield $name.': list check' => [$method, $path, ['expectedVersion' => 1, 'check' => []]];
            yield $name.': list of checks' => [$method, $path, ['expectedVersion' => 1, 'check' => [$grit]]];
        }

        yield 'move: no version' => ['POST', '/mine/checks/luck/move', ['index' => 0]];
        yield 'move: no index' => ['POST', '/mine/checks/luck/move', ['expectedVersion' => 1]];
        yield 'move: text index' => ['POST', '/mine/checks/luck/move', ['expectedVersion' => 1, 'index' => '0']];
        yield 'move: decimal index' => ['POST', '/mine/checks/luck/move', ['expectedVersion' => 1, 'index' => 0.5]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndChangesNothing(string $method, string $path, array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        $this->client->jsonRequest($method, self::BASE.$path, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertNotSame('', $this->error());
        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsRefused(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        foreach (['POST' => '/mine/checks', 'PUT' => '/mine/checks/luck'] as $method => $path) {
            $this->client->request($method, self::BASE.$path, [], [], ['CONTENT_TYPE' => 'application/json'], '{nope');
            self::assertResponseStatusCodeSame(400, $method.' malformed');

            $this->client->request($method, self::BASE.$path, [], [], ['CONTENT_TYPE' => 'text/plain'], '{"expectedVersion": 1}');
            self::assertResponseStatusCodeSame(415, $method.' content type');
        }

        $this->client->request('POST', self::BASE.'/mine/checks/luck/move', [], [], ['CONTENT_TYPE' => 'application/json'], '[1');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', self::BASE.'/mine/checks/luck/move', [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');
        self::assertResponseStatusCodeSame(415);

        self::assertSame($before, $this->checks());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}>
     */
    public static function invalidChecks(): iterable
    {
        $grit = self::grit();
        $noKey = $grit;
        unset($noKey['key']);

        yield 'dice' => [[...$grit, 'dice' => '2x6'], 'checks[2].dice: '];
        yield 'no key' => [$noKey, 'checks[2].key: '];
        yield 'key not text' => [[...$grit, 'key' => 7], 'checks[2].key: '];
        yield 'unknown property' => [[...$grit, 'colour' => 'red'], 'checks[2].colour: '];
        yield 'no bands' => [[...$grit, 'bands' => []], 'checks[2].bands: '];
        yield 'bands not a list' => [[...$grit, 'bands' => 'hit'], 'checks[2].bands: '];
    }

    /**
     * @param array<string, mixed> $check
     * @param non-empty-string     $prefix
     */
    #[Test]
    #[DataProvider('invalidChecks')]
    public function anInvalidCheckIsUnprocessableWithItsPath(array $check, string $prefix): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();

        $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => 1, 'check' => $check]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($prefix, $this->error());
        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aReferencedCheckReplacedWithoutAStringKeyFailsOnTheKeyPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $skill = (array) self::at($before, 'checks.0');
        unset($skill['key']);

        foreach ([$skill, [...$skill, 'key' => 7]] as $check) {
            $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/skill', ['expectedVersion' => 1, 'check' => $check]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringStartsWith('checks[0].key: ', $this->error());
        }

        self::assertSame($before, $this->checks());
    }

    /**
     * @return array<string, mixed>
     */
    private static function grit(): array
    {
        return ['key' => 'grit', 'label' => 'Grit', 'dice' => '2d6', 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 7], ['key' => 'hit', 'label' => 'Hit']]];
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

    /**
     * Publishes the fixture and copies it as the signed-in user's draft $key.
     */
    private function draft(string $key, string $fixture = 'valid/v2-checks'): void
    {
        $release = ReleaseArrays::fixture($fixture);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fa%03d', ++$this->releases), $release, false));
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key, 'source' => ['key' => self::at($release, 'gameSystem.key')]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<mixed>
     */
    private function checks(string $key = 'mine'): array
    {
        $this->client->request('GET', self::BASE.'/'.$key.'/checks');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<string> the keys of the checks of a read of the checks
     */
    private function keys(mixed $checks): array
    {
        $keys = [];
        foreach ((array) self::at($checks, 'checks') as $check) {
            $key = self::at($check, 'key');
            self::assertIsString($key);
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The value at a dotted path of nested arrays, or null.
     */
    private function at(mixed $node, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
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
