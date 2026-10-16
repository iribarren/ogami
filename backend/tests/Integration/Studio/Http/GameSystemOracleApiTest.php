<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Infrastructure\Http\GameSystemOracleController;
use App\Studio\Infrastructure\Http\GameSystemOraclesResponse;
use App\Studio\Infrastructure\Http\LikelihoodOracleItemResponse;
use App\Studio\Infrastructure\Http\LikelihoodTestAnswerResponse;
use App\Studio\Infrastructure\Http\OracleInUseResponse;
use App\Studio\Infrastructure\Http\OracleTableItemResponse;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The oracles of a GameSystem draft over the Studio API: the read, the oracle table and likelihood
 * oracle commands, and their test rolls and test answers. Owner only: another user's draft is not found.
 */
#[CoversClass(GameSystemOracleController::class)]
#[CoversClass(GameSystemOraclesResponse::class)]
#[CoversClass(OracleTableItemResponse::class)]
#[CoversClass(LikelihoodOracleItemResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
#[CoversClass(LikelihoodTestAnswerResponse::class)]
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
        'create likelihood' => ['POST', '/mine/likelihood-oracles'],
        'replace likelihood' => ['PUT', '/mine/likelihood-oracles/fate'],
        'delete likelihood' => ['DELETE', '/mine/likelihood-oracles/fate?expectedVersion=1'],
        'test answer' => ['POST', '/mine/likelihood-oracles/fate/test-answers'],
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
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'table' => $this->rumours(), 'oracle' => $this->luck(), 'likelihood' => 'even']);

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
        self::assertSame('Entry 1 of oracle table "escape-routes" nests "nowhere", but there is no oracle table "nowhere" in the table set.', $this->error());
    }

    #[Test]
    public function aTestRollThatMatchesNoEntryIsUnprocessable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        // 1d2 totals 1 or 2, which the only entry (3 to 3) never covers: the roll is deterministic enough to assert.
        $table = ['key' => 'gap', 'name' => 'Gap', 'dice' => '1d2', 'entries' => [['min' => 3, 'max' => 3, 'text' => 'Never']]];
        $this->client->jsonRequest('POST', self::BASE.'/mine/oracle-tables', ['expectedVersion' => 1, 'table' => $table]);
        self::assertResponseStatusCodeSame(201);
        $before = $this->oracles();

        $this->client->request('POST', self::BASE.'/mine/oracle-tables/gap/test-rolls');

        self::assertResponseStatusCodeSame(422);
        self::assertMatchesRegularExpression('/^Rolled [12] on table "gap", but no entry covers it\.$/', $this->error());
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aLikelihoodOracleIsCreatedAndAppended(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => $this->luck()]);

        self::assertResponseStatusCodeSame(201);
        $oracles = $this->json();
        self::assertSame(2, $oracles['version']);
        self::assertSame(['fate', 'luck'], $this->likelihoodKeys($oracles));
        self::assertSame($this->tableKeys($this->oracles()), $this->tableKeys($oracles));
        self::assertEquals($oracles, $this->oracles());
    }

    #[Test]
    public function aLikelihoodOracleIsReplacedAndRenamedAndItsTrackerRoundTripsUnchanged(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => $this->luck()]);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/luck', ['expectedVersion' => 2, 'oracle' => [...$this->luck(), 'key' => 'fortune', 'name' => 'Fortune']]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['version']);
        self::assertSame(['fate', 'fortune'], $this->likelihoodKeys($this->json()));

        $before = $this->oracles();
        $fate = self::at($before, 'likelihood.0.definition');
        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/fate', ['expectedVersion' => 3, 'oracle' => [...(array) $fate, 'name' => 'Fate (edited)']]);

        self::assertResponseIsSuccessful();
        self::assertSame('Fate (edited)', self::at($this->oracles(), 'likelihood.0.definition.name'));
        self::assertSame('chaos', self::at($this->oracles(), 'likelihood.0.definition.chaos.tracker'));
        self::assertSame($before['tables'], $this->json()['tables']);
    }

    #[Test]
    public function anUnreferencedLikelihoodOracleIsDeletedWithTheVersionInTheQuery(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => $this->luck()]);

        foreach (['', '?expectedVersion=', '?expectedVersion=one', '?expectedVersion=1.5'] as $query) {
            $this->client->request('DELETE', self::BASE.'/mine/likelihood-oracles/luck'.$query);
            self::assertResponseStatusCodeSame(400, $query);
        }
        self::assertSame(2, $this->oracles()['version']);

        $this->client->request('DELETE', self::BASE.'/mine/likelihood-oracles/luck?expectedVersion=2');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['version']);
        self::assertSame(['fate'], $this->likelihoodKeys($this->json()));
    }

    #[Test]
    public function aReferencedLikelihoodOracleCannotBeRenamedOrDeletedAndTheErrorListsWhere(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();
        $paths = (array) self::at($before, 'likelihood.0.usedBy');
        self::assertNotSame([], $paths);

        $this->client->request('DELETE', self::BASE.'/mine/likelihood-oracles/fate?expectedVersion=1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);
        self::assertStringContainsString(implode(', ', array_filter($paths, is_string(...))), $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/fate', ['expectedVersion' => 1, 'oracle' => [...(array) self::at($before, 'likelihood.0.definition'), 'key' => 'destiny']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, $this->json()['paths']);

        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aStaleVersionIsConflictingOnEveryLikelihoodWriteAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $readByBoth = $this->oracles()['version'];
        self::assertIsInt($readByBoth);
        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => $readByBoth, 'oracle' => $this->luck()]);
        self::assertResponseStatusCodeSame(201);
        $before = $this->oracles();

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => $readByBoth, 'oracle' => [...$this->luck(), 'key' => 'fortune']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GameSystem "mine" was changed by another request. Reload it and try again.', $this->error());
        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/luck', ['expectedVersion' => $readByBoth, 'oracle' => [...$this->luck(), 'name' => 'Other']]);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('DELETE', self::BASE.'/mine/likelihood-oracles/luck?expectedVersion='.$readByBoth);
        self::assertResponseStatusCodeSame(409);

        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aLikelihoodKeyAlreadyUsedByAnotherOracleIsConflicting(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => $this->luck()]);
        $before = $this->oracles();

        foreach (['complications', 'fate'] as $taken) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 2, 'oracle' => [...$this->luck(), 'key' => $taken]]);
            self::assertResponseStatusCodeSame(409, $taken);
            self::assertStringContainsString($taken, $this->error());
        }

        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/luck', ['expectedVersion' => 2, 'oracle' => [...$this->luck(), 'key' => 'fate']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aMissingLikelihoodOracleIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/nothing', ['expectedVersion' => 1, 'oracle' => $this->luck()]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', self::BASE.'/mine/likelihood-oracles/nothing?expectedVersion=1');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles/nothing/test-answers', ['likelihood' => 'even', 'chaosFactor' => null]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('The draft has no oracle "nothing".', $this->error());
        self::assertSame(1, $this->oracles()['version']);
    }

    #[Test]
    public function anInvalidLikelihoodOracleIsUnprocessableWithItsPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => [...$this->luck(), 'levels' => []]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('oracles.likelihood', $this->error());
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aVersion1DraftRefusesV2OnlyLikelihoodFieldsAndEditsItsOwn(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');
        $before = $this->oracles('journal');
        $chaos = ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5];

        foreach ([['randomEvent' => ['table' => 'weather'], 'chaos' => $chaos], ['chaos' => [...$chaos, 'tracker' => 'chaos']]] as $v2Only) {
            $this->client->jsonRequest('POST', self::BASE.'/journal/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => [...$this->luck(), ...$v2Only]]);
            self::assertResponseStatusCodeSame(422, (string) json_encode($v2Only));
            self::assertStringContainsString('unknown property', $this->error());
        }
        self::assertSame($before, $this->oracles('journal'));

        $this->client->jsonRequest('POST', self::BASE.'/journal/likelihood-oracles', ['expectedVersion' => 1, 'oracle' => [...$this->luck(), 'chaos' => $chaos]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['fate', 'luck'], $this->likelihoodKeys($this->json()));
    }

    /**
     * @return iterable<string, array{string, string, string|null, mixed, int}>
     */
    public static function malformedLikelihoodRequests(): iterable
    {
        $oracle = ['key' => 'luck', 'name' => 'Luck', 'sides' => 100, 'levels' => [['key' => 'low', 'label' => 'Low', 'target' => 25]]];

        foreach (['POST' => '/mine/likelihood-oracles', 'PUT' => '/mine/likelihood-oracles/fate'] as $method => $path) {
            yield $method.' not a JSON object' => [$method, $path, '[1, 2]', null, 400];
            yield $method.' not JSON' => [$method, $path, '{"oracle":', null, 400];
            yield $method.' no expectedVersion' => [$method, $path, null, ['oracle' => $oracle], 400];
            yield $method.' expectedVersion a string' => [$method, $path, null, ['expectedVersion' => '1', 'oracle' => $oracle], 400];
            yield $method.' no oracle' => [$method, $path, null, ['expectedVersion' => 1], 400];
            yield $method.' oracle a list' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => [$oracle]], 400];
            yield $method.' oracle empty list' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => []], 400];
            yield $method.' oracle a string' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => 'luck'], 400];
            yield $method.' key of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => [...$oracle, 'key' => 7]], 422];
            yield $method.' sides of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => [...$oracle, 'sides' => 'many']], 422];
            yield $method.' levels of the wrong type' => [$method, $path, null, ['expectedVersion' => 1, 'oracle' => [...$oracle, 'levels' => 'low']], 422];
        }

        yield 'test answer not a JSON object' => ['POST', '/mine/likelihood-oracles/fate/test-answers', '[1]', null, 400];
        yield 'test answer no likelihood' => ['POST', '/mine/likelihood-oracles/fate/test-answers', null, ['chaosFactor' => 5], 400];
        yield 'test answer likelihood a number' => ['POST', '/mine/likelihood-oracles/fate/test-answers', null, ['likelihood' => 1], 400];
        yield 'test answer chaosFactor a string' => ['POST', '/mine/likelihood-oracles/fate/test-answers', null, ['likelihood' => 'even', 'chaosFactor' => '5'], 400];
        yield 'test answer chaosFactor a float' => ['POST', '/mine/likelihood-oracles/fate/test-answers', null, ['likelihood' => 'even', 'chaosFactor' => 5.5], 400];
    }

    /**
     * @param string|null       $raw  a raw body, sent as given
     * @param array<mixed>|null $json the body to encode when $raw is null
     */
    #[Test]
    #[DataProvider('malformedLikelihoodRequests')]
    public function aMalformedOrInvalidLikelihoodBodyChangesNothing(string $method, string $path, ?string $raw, ?array $json, int $status): void
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
    public function aLikelihoodBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach ([['POST', '/mine/likelihood-oracles'], ['PUT', '/mine/likelihood-oracles/fate'], ['POST', '/mine/likelihood-oracles/fate/test-answers']] as [$method, $path]) {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'text/plain'], content: 'oracle');

            self::assertResponseStatusCodeSame(415);
        }
    }

    #[Test]
    public function aTestAnswerReturnsTheAnswerAndNeverChangesTheDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();
        $this->scriptRolls(40);

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles/fate/test-answers', ['likelihood' => 'even', 'chaosFactor' => null]);

        self::assertResponseIsSuccessful();
        $result = $this->json();
        foreach (['answer' => 'yes', 'roll' => 40, 'sides' => 100, 'effectiveTarget' => 50, 'likelihood' => 'even', 'likelihoodLabel' => '50/50', 'chaosFactor' => 5, 'randomEvent' => null] as $field => $expected) {
            self::assertSame($expected, self::at($result, 'answer.'.$field), $field);
        }
        self::assertNull($result['randomEvent']);
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aTestAnswerThatSignalsARandomEventRollsTheEventTable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $fate = (array) self::at($this->oracles(), 'likelihood.0.definition');
        $this->client->jsonRequest('PUT', self::BASE.'/mine/likelihood-oracles/fate', ['expectedVersion' => 1, 'oracle' => [...$fate, 'randomEvent' => ['table' => 'complications']]]);
        self::assertResponseIsSuccessful();
        $before = $this->oracles();
        // A roll of 33 is a double of 3, within the chaos factor 5; the weighted event table then rolls 3 of 4.
        $this->scriptRolls(33, 3);

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles/fate/test-answers', ['likelihood' => 'even', 'chaosFactor' => 5]);

        self::assertResponseIsSuccessful();
        $result = $this->json();
        self::assertSame(['yes', 'complications'], [self::at($result, 'answer.answer'), self::at($result, 'answer.randomEvent')]);
        self::assertSame(['complications', 'Spotted!'], [self::at($result, 'randomEvent.table'), self::at($result, 'randomEvent.steps.0.text')]);
        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aTestAnswerWithAnUnknownLevelOrAChaosFactorOutOfRangeIsUnprocessable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->oracles();

        foreach ([['likelihood' => 'certain', 'chaosFactor' => null], ['likelihood' => 'even', 'chaosFactor' => 10], ['likelihood' => 'even', 'chaosFactor' => 0]] as $question) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles/fate/test-answers', $question);

            self::assertResponseStatusCodeSame(422, (string) json_encode($question));
            self::assertNotSame('', $this->error());
        }

        self::assertSame($before, $this->oracles());
    }

    #[Test]
    public function aTestAnswerOfADraftWhoseRandomEventTableIsInvalidIsUnprocessable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $draft = $this->draft('mine');
        $this->storeDocument('mine', ReleaseArrays::with(ReleaseArrays::with($draft, 'oracles.likelihood.0.randomEvent', ['table' => 'nowhere']), 'oracles.tables.1.entries.3.table', 'nowhere'));
        $this->scriptRolls(33);

        $this->client->jsonRequest('POST', self::BASE.'/mine/likelihood-oracles/fate/test-answers', ['likelihood' => 'even', 'chaosFactor' => 5]);

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
     * A likelihood oracle with no chaos and no extension.
     *
     * @return array<string, mixed>
     */
    private function luck(): array
    {
        return ['key' => 'luck', 'name' => 'Luck', 'sides' => 100, 'levels' => [['key' => 'low', 'label' => 'Low', 'target' => 25], ['key' => 'high', 'label' => 'High', 'target' => 75]]];
    }

    /**
     * Makes Randomness roll these numbers, in order, for the next request.
     */
    private function scriptRolls(int ...$numbers): void
    {
        self::getContainer()->set(RandomNumberGenerator::class, new ScriptedRandomNumberGenerator(...$numbers));
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
     * @return list<string> the keys of the likelihood oracles of a read of the oracles
     */
    private function likelihoodKeys(mixed $oracles): array
    {
        $keys = [];
        foreach ((array) self::at($oracles, 'likelihood') as $oracle) {
            $key = self::at($oracle, 'definition.key');
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
