<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Infrastructure\Http\GameSystemOracleController;
use App\Studio\Infrastructure\Http\GameSystemOraclesResponse;
use App\Studio\Infrastructure\Http\LikelihoodOracleItemResponse;
use App\Studio\Infrastructure\Http\OracleInUseResponse;
use App\Studio\Infrastructure\Http\OracleTableItemResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The oracles of a GameSystem draft over the Studio API: the read, and the oracle table commands
 * with their test rolls. Owner only: another user's draft is not found.
 */
#[CoversClass(GameSystemOracleController::class)]
#[CoversClass(GameSystemOraclesResponse::class)]
#[CoversClass(OracleTableItemResponse::class)]
#[CoversClass(LikelihoodOracleItemResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
final class GameSystemOracleApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const array ROUTES = [
        'read' => ['GET', '/mine/oracles'],
        'create' => ['POST', '/mine/oracle-tables'],
        'replace' => ['PUT', '/mine/oracle-tables/complications'],
        'delete' => ['DELETE', '/mine/oracle-tables/complications?expectedVersion=1'],
        'test roll' => ['POST', '/mine/oracle-tables/complications/test-rolls'],
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
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'table' => $this->rumours()]);

            self::assertResponseStatusCodeSame(404, $method.' '.$key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        self::assertSame(1, $this->oracles()['version'], 'Nothing changed.');
    }

    #[Test]
    public function theOraclesAreReadWithTheirVersionDefinitionAndUsage(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('GET', self::BASE.'/mine/oracles');

        self::assertResponseIsSuccessful();
        $oracles = $this->json();
        self::assertSame([1, 2], [$oracles['version'], $oracles['schemaVersion']]);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($oracles));
        self::assertSame('1d6', self::at($oracles, 'tables.0.definition.dice'));
        self::assertSame('firefight', self::at($oracles, 'tables.1.definition.entries.2.sceneType'));
        self::assertSame('alarm', self::at($oracles, 'tables.1.definition.entries.0.effects.0.tracker'));
        self::assertContains('sceneTypes[1].play[1]', (array) self::at($oracles, 'tables.1.usedBy'));
        self::assertSame('fate', self::at($oracles, 'likelihood.0.definition.key'));
        self::assertSame('chaos', self::at($oracles, 'likelihood.0.definition.chaos.tracker'));
        self::assertContains('sceneTypes[3].play[0]', (array) self::at($oracles, 'likelihood.0.usedBy'));
    }

    #[Test]
    public function aVersion1DraftReadsAsItWasStored(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $oracles = $this->oracles('journal');

        self::assertSame(1, $oracles['schemaVersion']);
        self::assertSame(['weather', 'storm-kind'], $this->tableKeys($oracles));
        self::assertSame(['oracles.tables[0].entries[1].table'], self::at($oracles, 'tables.1.usedBy'));
        self::assertNull(self::at($oracles, 'likelihood.0.definition.randomEvent'));
    }

    #[Test]
    public function aTableIsCreatedAndAppended(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => $this->rumours()]);

        self::assertResponseStatusCodeSame(201);
        $oracles = $this->json();
        self::assertSame(2, $oracles['version']);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes', 'rumours'], $this->tableKeys($oracles));
        // The database stores the document as jsonb, which does not keep the order of an object's keys.
        self::assertEquals($oracles, $this->oracles());
    }

    #[Test]
    public function aTableIsReplacedAndRenamedAndItsV2ExtensionsRoundTripUnchanged(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => $this->rumours()]);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/rumours', ['expectedVersion' => 2, 'table' => [...$this->rumours(), 'key' => 'gossip', 'name' => 'Gossip']]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['version']);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes', 'gossip'], $this->tableKeys($this->json()));

        $before = $this->oracles();
        $complications = self::at($before, 'tables.1.definition');
        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/complications', ['expectedVersion' => 3, 'table' => $complications]);

        self::assertResponseIsSuccessful();
        self::assertEquals($complications, self::at($this->json(), 'tables.1.definition'), 'Entry key, sceneType and effects are kept as given.');
        self::assertSame($before['likelihood'], $this->json()['likelihood']);
    }

    #[Test]
    public function anUnreferencedTableIsDeletedWithTheVersionInTheQuery(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => $this->rumours()]);

        $this->client->request('DELETE', self::BASE.'/mine/oracle-tables/rumours?expectedVersion=2');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['version']);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->tableKeys($this->json()));
    }

    #[Test]
    public function aTestRollReturnsTheStepsAndNeverChangesTheDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        $this->client->request('POST', self::BASE.'/mine/oracle-tables/heist-scenes/test-rolls');

        self::assertResponseIsSuccessful();
        $result = $this->json();
        self::assertSame('heist-scenes', $result['table']);
        self::assertSame(['heist-scenes', '1d6'], [self::at($result, 'steps.0.tableKey'), self::at($result, 'steps.0.dice')]);
        self::assertSame($before, $this->oracles());

        $this->client->request('POST', self::BASE.'/mine/oracle-tables/nothing/test-rolls');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('The draft has no oracle "nothing".', $this->error());
    }

    #[Test]
    public function aTestRollOfADraftWhoseTablesAreInvalidIsUnprocessable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $draft = $this->draft('mine');
        $this->storeDocument('mine', ReleaseArrays::with($draft, 'oracles.tables.2.entries.0.table', 'nowhere'));

        $this->client->request('POST', self::BASE.'/mine/oracle-tables/escape-routes/test-rolls');

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', $this->error());
    }

    /**
     * @return iterable<string, array{string, string, string|null, mixed, int}>
     */
    public static function malformedRequests(): iterable
    {
        $table = ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]];

        foreach (['POST' => '/mine/oracle-tables', 'PUT' => '/mine/oracle-tables/complications'] as $method => $path) {
            yield $method.' not a JSON object' => [$method, $path, '[1, 2]', null, 400];
            yield $method.' not JSON' => [$method, $path, '{"table":', null, 400];
            yield $method.' no expectedVersion' => [$method, $path, null, ['table' => $table], 400];
            yield $method.' expectedVersion a string' => [$method, $path, null, ['expectedVersion' => '1', 'table' => $table], 400];
            yield $method.' expectedVersion a float' => [$method, $path, null, ['expectedVersion' => 1.5, 'table' => $table], 400];
            yield $method.' no table' => [$method, $path, null, ['expectedVersion' => 1], 400];
            yield $method.' table a list' => [$method, $path, null, ['expectedVersion' => 1, 'table' => [$table]], 400];
            yield $method.' table empty list' => [$method, $path, null, ['expectedVersion' => 1, 'table' => []], 400];
            yield $method.' table a string' => [$method, $path, null, ['expectedVersion' => 1, 'table' => 'rumours'], 400];
            yield $method.' key of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'table' => [...$table, 'key' => 7]], 422];
            yield $method.' entries of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'table' => [...$table, 'entries' => 'text']], 422];
            yield $method.' entry of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'table' => [...$table, 'entries' => ['text']]], 422];
        }
    }

    /**
     * @param string|null       $raw  a raw body, sent as given
     * @param array<mixed>|null $json the body to encode when $raw is null
     */
    #[Test]
    #[DataProvider('malformedRequests')]
    public function aMalformedOrInvalidBodyChangesNothing(string $method, string $path, ?string $raw, ?array $json, int $status): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        if (null === $raw) {
            $this->client->jsonRequest($method, self::BASE.$path, $json ?? []);
        } else {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);
        }

        self::assertResponseStatusCodeSame($status);
        self::assertNotSame('', $this->error());
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach ([['POST', '/mine/oracle-tables'], ['PUT', '/mine/oracle-tables/complications']] as [$method, $path]) {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'text/plain'], content: 'table');

            self::assertResponseStatusCodeSame(415);
        }
    }

    #[Test]
    public function theDeleteVersionMustBeAnInteger(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => $this->rumours()]);

        foreach (['', '?expectedVersion=', '?expectedVersion=one', '?expectedVersion=1.5'] as $query) {
            $this->client->request('DELETE', self::BASE.'/mine/oracle-tables/rumours'.$query);

            self::assertResponseStatusCodeSame(400, $query);
        }

        self::assertSame(2, $this->oracles()['version']);
    }

    #[Test]
    public function aStaleVersionIsConflictingOnEveryWriteAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $readByBoth = $this->oracles()['version'];
        self::assertIsInt($readByBoth);
        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => $readByBoth, 'table' => $this->rumours()]);
        self::assertResponseStatusCodeSame(201);
        $before = $this->oracles();

        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => $readByBoth, 'table' => [...$this->rumours(), 'key' => 'gossip']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GameSystem "mine" was changed by another request. Reload it and try again.', $this->error());
        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/rumours', ['expectedVersion' => $readByBoth, 'table' => [...$this->rumours(), 'name' => 'Other']]);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('DELETE', self::BASE.'/mine/oracle-tables/rumours?expectedVersion='.$readByBoth);
        self::assertResponseStatusCodeSame(409);

        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aKeyAlreadyUsedByAnotherOracleIsConflicting(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        foreach (['complications', 'fate'] as $taken) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => [...$this->rumours(), 'key' => $taken]]);
            self::assertResponseStatusCodeSame(409, $taken);
            self::assertStringContainsString($taken, $this->error());
        }

        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/escape-routes', ['expectedVersion' => 1, 'table' => [...(array) self::at($before, 'tables.2.definition'), 'key' => 'heist-scenes']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aMissingTableIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/nothing', ['expectedVersion' => 1, 'table' => $this->rumours()]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', self::BASE.'/mine/oracle-tables/nothing?expectedVersion=1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->oracles()['version']);
    }

    #[Test]
    public function aReferencedTableCannotBeRenamedOrDeletedAndTheErrorListsWhere(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();
        $paths = (array) self::at($before, 'tables.1.usedBy');
        self::assertNotSame([], $paths);

        $this->client->request('DELETE', self::BASE.'/mine/oracle-tables/complications?expectedVersion=1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);
        self::assertStringContainsString(implode(', ', array_filter($paths, is_string(...))), $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/oracle-tables/complications', ['expectedVersion' => 1, 'table' => [...(array) self::at($before, 'tables.1.definition'), 'key' => 'troubles']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);

        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function anInvalidTableIsUnprocessableWithItsPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => [...$this->rumours(), 'entries' => []]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('oracles.tables: ', $this->error());
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aVersion1DraftRefusesV2OnlyFieldsAndEditsItsOwn(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');
        $table = ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True', 'weight' => 2]]];
        $before = $this->oracles('journal');

        $this->client->jsonRequest('POST', self::BASE.'/journal/oracle-tables', ['expectedVersion' => 1, 'table' => ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True', 'key' => 'true']]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('oracles.tables[2].entries[0].key: unknown property', $this->error());
        $this->client->jsonRequest('POST', self::BASE.'/journal/oracle-tables', ['expectedVersion' => 1, 'table' => ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True', 'sceneType' => 'x']]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->oracles('journal'));

        $this->client->jsonRequest('POST', self::BASE.'/journal/oracle-tables', ['expectedVersion' => 1, 'table' => $table]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['weather', 'storm-kind', 'rumours'], $this->tableKeys($this->json()));
    }

    /**
     * @return array<string, mixed>
     */
    private function rumours(): array
    {
        return ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True', 'weight' => 2, 'key' => 'true'], ['text' => 'False', 'key' => 'false']]];
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
     *
     * @return array<mixed> the copied release document
     */
    private function draft(string $key, string $fixture = 'valid/v2-every-part'): array
    {
        $release = ReleaseArrays::fixture($fixture);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9%03d', ++$this->releases), $release, false));
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key, 'source' => ['key' => self::at($release, 'gameSystem.key')]]);
        self::assertResponseStatusCodeSame(201);

        return $release;
    }

    /**
     * @param array<mixed> $document
     */
    private function storeDocument(string $key, array $document): void
    {
        $repository = self::getContainer()->get(GameSystemRepository::class);
        $draft = $repository->ofKey($key);
        self::assertNotNull($draft);
        $draft->restore($document, new \DateTimeImmutable());
        $repository->save($draft);
    }

    /**
     * @return array<mixed>
     */
    private function oracles(string $key = 'mine'): array
    {
        $this->client->request('GET', self::BASE.'/'.$key.'/oracles');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<string> the keys of the tables of a read of the oracles
     */
    private function tableKeys(mixed $oracles): array
    {
        $keys = [];
        foreach ((array) self::at($oracles, 'tables') as $table) {
            $key = self::at($table, 'definition.key');
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
