<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\GameSystemNarrativeController;
use App\Studio\Infrastructure\Http\GameSystemNarrativeResponse;
use App\Studio\Infrastructure\Http\NarrativeCatalogResponse;
use App\Studio\Infrastructure\Http\NarrativeItemResponse;
use App\Studio\Infrastructure\Http\OracleInUseResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The narrative sections of a GameSystem draft over the Studio API: the read and the item commands
 * for trackers, tags, fact slots, Scene Types and flows. Owner only: another user's draft is not found.
 */
#[CoversClass(GameSystemNarrativeController::class)]
#[CoversClass(GameSystemNarrativeResponse::class)]
#[CoversClass(NarrativeItemResponse::class)]
#[CoversClass(NarrativeCatalogResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
final class GameSystemNarrativeApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const array ROUTES = [
        'read' => ['GET', '/mine/narrative'],
        'create' => ['POST', '/mine/narrative/tags'],
        'replace' => ['PUT', '/mine/narrative/tags/gang'],
        'delete' => ['DELETE', '/mine/narrative/tags/gang?expectedVersion=1'],
    ];

    private KernelBrowser $client;
    private int $releases = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function aReadThatFailsAfterAWriteRollsTheWriteBack(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();
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
            $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/tags', ['expectedVersion' => 1, 'item' => $this->tag()]);
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertGreaterThan(0, $listener->fired, 'The write was flushed before the read failed.');
        self::assertTrue($failed, 'The corrupted document makes the read fail.');
        self::assertSame($before, $this->narrative(), 'The write was rolled back with the failed read, version included.');
    }

    #[Test]
    public function aReadThatFailsAfterADeleteRollsTheDeleteBack(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/tags', ['expectedVersion' => 1, 'item' => $this->tag()]);
        $before = $this->narrative();
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
            $this->client->request('DELETE', self::BASE.'/mine/narrative/tags/gang?expectedVersion=2');
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertGreaterThan(0, $listener->fired, 'The write was flushed before the read failed.');
        self::assertTrue($failed, 'The corrupted document makes the read fail.');
        self::assertSame($before, $this->narrative(), 'The tag is still there, version included.');
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
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'item' => $this->tag()]);

            self::assertResponseStatusCodeSame(404, $method.' '.$key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        self::assertSame(1, $this->narrative()['version'], 'Nothing changed.');
    }

    #[Test]
    public function theNarrativeSectionsAreReadWithVersionDefinitionAndUsage(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('GET', self::BASE.'/mine/narrative');

        self::assertResponseIsSuccessful();
        $narrative = $this->json();
        self::assertSame([1, 2], [$narrative['version'], $narrative['schemaVersion']]);
        self::assertSame(['alarm', 'edge', 'chaos', 'heat'], $this->keys($narrative, 'trackers'));
        self::assertSame([], $narrative['tags']);
        self::assertSame(['target', 'client', 'ambition'], $this->keys($narrative, 'factSlots'));
        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway', 'downtime'], $this->keys($narrative, 'sceneTypes'));
        self::assertSame(['heist', 'quick'], $this->keys($narrative, 'flows'));
        self::assertSame('Chaos factor', self::at($narrative, 'trackers.2.definition.name'));
        self::assertSame(['flows[0].trackers', 'oracles.likelihood[0].chaos'], self::at($narrative, 'trackers.2.usedBy'));
        self::assertSame(['flows[0].phases[2].selection', 'oracles.tables[1].entries[3]'], self::at($narrative, 'sceneTypes.3.usedBy'));
        self::assertSame([], self::at($narrative, 'sceneTypes.4.usedBy'));
        self::assertSame('heist', self::at($narrative, 'flows.0.definition.key'));
    }

    #[Test]
    public function theCatalogOffersTablesLikelihoodOraclesChecksAndFields(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('rules', 'valid/v2-checks');
        $this->draft('mine');

        $catalog = self::at($this->narrative('mine'), 'catalog');

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], array_column((array) self::at($catalog, 'tables'), 'key'));
        self::assertSame(['patrol', 'lucky-break', 'spotted', 'run'], self::at($catalog, 'tables.1.entries'));
        self::assertSame('Complications', self::at($catalog, 'tables.1.name'));
        self::assertSame([['key' => 'fate', 'name' => 'Fate question', 'levels' => ['unlikely', 'even', 'likely']]], self::at($catalog, 'likelihood'));

        $catalog = self::at($this->narrative('rules'), 'catalog');

        self::assertSame(['skill', 'luck'], array_column((array) self::at($catalog, 'checks'), 'key'));
        self::assertSame(['fail', 'success', 'crit'], self::at($catalog, 'checks.0.bands'));
        self::assertSame(['default' => 13, 'min' => 9, 'max' => 29], self::at($catalog, 'checks.0.difficulty'));
        self::assertNull(self::at($catalog, 'checks.1.difficulty'));
        self::assertSame([['key' => 'hands', 'label' => 'Hands']], self::at($catalog, 'fields'));
    }

    #[Test]
    public function aCheckDifficultyWithoutBoundsIsServedWithNullBounds(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('rules', 'valid/v2-checks', ['checks.0.difficulty.min']);
        $this->draft('open', 'valid/v2-checks', ['checks.0.difficulty.min', 'checks.0.difficulty.max']);

        self::assertSame(['default' => 13, 'min' => null, 'max' => 29], self::at($this->narrative('rules'), 'catalog.checks.0.difficulty'));
        self::assertSame(['default' => 13, 'min' => null, 'max' => null], self::at($this->narrative('open'), 'catalog.checks.0.difficulty'));
        self::assertStringContainsString('"difficulty":{"default":13,"min":null,"max":null}', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aVersion1DraftReadsAsEmptySections(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $narrative = $this->narrative('journal');

        self::assertSame([1, 1], [$narrative['version'], $narrative['schemaVersion']]);
        foreach (['trackers', 'tags', 'factSlots', 'sceneTypes', 'flows'] as $section) {
            self::assertSame([], $narrative[$section], $section);
        }
        self::assertSame([[], []], [self::at($narrative, 'catalog.checks'), self::at($narrative, 'catalog.fields')]);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function newItems(): iterable
    {
        yield 'a tracker' => ['trackers', 'trackers', ['key' => 'doom', 'name' => 'Doom', 'kind' => 'clock', 'segments' => 4]];
        yield 'a tag' => ['tags', 'tags', ['key' => 'gang', 'label' => 'Gang']];
        yield 'a tag limited to some entity kinds' => ['tags', 'tags', ['key' => 'gang', 'label' => 'Gang', 'appliesTo' => ['npc', 'thread']]];
        yield 'a fact slot' => ['fact-slots', 'factSlots', ['key' => 'rival', 'label' => 'Rival', 'type' => 'npc']];
        yield 'a Scene Type' => ['scene-types', 'sceneTypes', ['key' => 'chase', 'name' => 'Chase', 'purpose' => 'Run.', 'oracles' => [], 'setup' => [], 'play' => [['key' => 'go', 'kind' => 'prompt', 'title' => 'Go']], 'closing' => []]];
        yield 'a flow' => ['flows', 'flows', ['key' => 'solo', 'name' => 'Solo', 'defaultView' => 'focus', 'oracles' => [], 'trackers' => [], 'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['legwork']]]]]];
    }

    /**
     * @param array<string, mixed> $item
     */
    #[Test]
    #[DataProvider('newItems')]
    public function aNewItemIsAppendedAndTheReadModelReturned(string $slug, string $section, array $item): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();

        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/'.$slug, ['expectedVersion' => 1, 'item' => $item]);

        self::assertResponseStatusCodeSame(201);
        $narrative = $this->json();
        self::assertSame(2, $narrative['version']);
        self::assertSame([...$this->keys($before, $section), $item['key']], $this->keys($narrative, $section));
        self::assertEquals($item, self::at($narrative, $section.'.'.\count((array) $before[$section]).'.definition'));
        self::assertSame([], self::at($narrative, $section.'.'.\count((array) $before[$section]).'.usedBy'));
        self::assertEquals($narrative, $this->narrative(), 'The response is the read model.');
    }

    #[Test]
    public function anItemIsReplacedInPlaceAndRenamedWhenTheBodyHasAnotherKey(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/tags', ['expectedVersion' => 1, 'item' => $this->tag()]);
        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/tags', ['expectedVersion' => 2, 'item' => ['key' => 'mob', 'label' => 'Mob']]);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/narrative/tags/gang', ['expectedVersion' => 3, 'item' => ['key' => 'crew', 'label' => 'The crew']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['version']);
        self::assertSame(['crew', 'mob'], $this->keys($this->json(), 'tags'));
        self::assertSame('The crew', self::at($this->json(), 'tags.0.definition.label'));

        $this->client->jsonRequest('PUT', self::BASE.'/mine/narrative/flows/quick', ['expectedVersion' => 4, 'item' => [...(array) self::at($this->narrative(), 'flows.1.definition'), 'key' => 'speedy']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['heist', 'speedy'], $this->keys($this->json(), 'flows'));
    }

    #[Test]
    public function aSavedDefaultFlowClearsTheFlagOnTheOthers(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/narrative/flows/quick', ['expectedVersion' => 1, 'item' => [...(array) self::at($this->narrative(), 'flows.1.definition'), 'default' => true]]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(self::at($this->json(), 'flows.1.definition.default'));
        self::assertNotTrue(self::at($this->json(), 'flows.0.definition.default'));
    }

    #[Test]
    public function anUnusedItemIsDeleted(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('DELETE', self::BASE.'/mine/narrative/scene-types/downtime?expectedVersion=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway'], $this->keys($this->json(), 'sceneTypes'));

        $this->client->request('DELETE', self::BASE.'/mine/narrative/flows/quick?expectedVersion=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['heist'], $this->keys($this->json(), 'flows'));
    }

    /**
     * @return iterable<string, array{string, string|null, mixed}>
     */
    public static function malformedBodies(): iterable
    {
        $item = ['key' => 'gang', 'label' => 'Gang'];

        foreach (['POST' => '/mine/narrative/tags', 'PUT' => '/mine/narrative/tags/gang'] as $method => $path) {
            yield $method.' not a JSON object' => [$method.' '.$path, '[1, 2]', null];
            yield $method.' not JSON' => [$method.' '.$path, '{"item":', null];
            yield $method.' no expectedVersion' => [$method.' '.$path, null, ['item' => $item]];
            yield $method.' expectedVersion a string' => [$method.' '.$path, null, ['expectedVersion' => '1', 'item' => $item]];
            yield $method.' expectedVersion a float' => [$method.' '.$path, null, ['expectedVersion' => 1.5, 'item' => $item]];
            yield $method.' no item' => [$method.' '.$path, null, ['expectedVersion' => 1]];
            yield $method.' item null' => [$method.' '.$path, null, ['expectedVersion' => 1, 'item' => null]];
            yield $method.' item a list' => [$method.' '.$path, null, ['expectedVersion' => 1, 'item' => [$item]]];
            yield $method.' item an empty list' => [$method.' '.$path, null, ['expectedVersion' => 1, 'item' => []]];
            yield $method.' item a string' => [$method.' '.$path, null, ['expectedVersion' => 1, 'item' => 'gang']];
        }

        yield 'DELETE without expectedVersion' => ['DELETE /mine/narrative/scene-types/downtime', null, null];
        yield 'DELETE expectedVersion not a number' => ['DELETE /mine/narrative/scene-types/downtime?expectedVersion=abc', null, null];
        yield 'DELETE expectedVersion a float' => ['DELETE /mine/narrative/scene-types/downtime?expectedVersion=1.5', null, null];
        yield 'DELETE expectedVersion empty' => ['DELETE /mine/narrative/scene-types/downtime?expectedVersion=', null, null];
    }

    /**
     * @param string|null       $raw  a raw body, sent as given
     * @param array<mixed>|null $json the body to encode when $raw is null
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsBadRequestAndChangesNothing(string $request, ?string $raw, ?array $json): void
    {
        [$method, $path] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();

        if (null === $raw) {
            $this->client->jsonRequest($method, self::BASE.$path, $json ?? []);
        } else {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);
        }

        self::assertResponseStatusCodeSame(400);
        self::assertNotSame('', $this->error());
        self::assertSame($before, $this->narrative());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupportedMediaType(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach ([['POST', '/mine/narrative/tags'], ['PUT', '/mine/narrative/tags/gang']] as [$method, $path]) {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'text/plain'], content: 'tag');

            self::assertResponseStatusCodeSame(415, $method);
            self::assertNotSame('', $this->error());
        }
        self::assertSame(1, $this->narrative()['version']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingTargets(): iterable
    {
        yield 'PUT an unknown section' => ['PUT /mine/narrative/widgets/gang'];
        yield 'POST an unknown section' => ['POST /mine/narrative/widgets'];
        yield 'DELETE an unknown section' => ['DELETE /mine/narrative/widgets/gang?expectedVersion=1'];
        yield 'a section by its document key, not its slug' => ['POST /mine/narrative/factSlots'];
        yield 'PUT an unknown item' => ['PUT /mine/narrative/tags/nothing'];
        yield 'PUT an item of another section' => ['PUT /mine/narrative/tags/alarm'];
        yield 'DELETE an unknown item' => ['DELETE /mine/narrative/flows/nothing?expectedVersion=1'];
    }

    #[Test]
    #[DataProvider('missingTargets')]
    public function anUnknownSectionOrItemIsNotFoundAndChangesNothing(string $request): void
    {
        [$method, $path] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();

        $this->client->jsonRequest($method, self::BASE.$path, ['expectedVersion' => 1, 'item' => $this->tag()]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->narrative());
    }

    #[Test]
    public function aStaleExpectedVersionIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/tags', ['expectedVersion' => 1, 'item' => $this->tag()]);
        $before = $this->narrative();

        foreach ([
            ['POST', '/mine/narrative/tags', ['expectedVersion' => 1, 'item' => ['key' => 'mob', 'label' => 'Mob']]],
            ['PUT', '/mine/narrative/tags/gang', ['expectedVersion' => 1, 'item' => ['key' => 'gang', 'label' => 'Changed']]],
            ['DELETE', '/mine/narrative/tags/gang?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(409, $method);
            self::assertNotSame('', $this->error());
        }
        self::assertSame($before, $this->narrative());
    }

    #[Test]
    public function aKeyAlreadyTakenInTheSectionIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();

        $this->client->jsonRequest('POST', self::BASE.'/mine/narrative/trackers', ['expectedVersion' => 1, 'item' => ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 4]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('The draft already has a tracker "alarm".', $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/narrative/scene-types/downtime', ['expectedVersion' => 1, 'item' => [...(array) self::at($before, 'sceneTypes.4.definition'), 'key' => 'getaway']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->narrative());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidItems(): iterable
    {
        yield 'POST a key that is not a slug' => ['POST /mine/narrative/tags', ['key' => 'Not A Key', 'label' => 'Gang'], 'tags[0].key'];
        yield 'POST a tag with an empty scope' => ['POST /mine/narrative/tags', ['key' => 'gang', 'label' => 'Gang', 'appliesTo' => []], 'tags[0].appliesTo'];
        yield 'POST a tag scoped to an unknown kind' => ['POST /mine/narrative/tags', ['key' => 'gang', 'label' => 'Gang', 'appliesTo' => ['faction']], 'tags[0].appliesTo[0]'];
        yield 'POST an unknown property' => ['POST /mine/narrative/tags', ['key' => 'gang', 'label' => 'Gang', 'colour' => 'red'], 'tags[0].colour'];
        yield 'POST a counter with min above max' => ['POST /mine/narrative/trackers', ['key' => 'doom', 'name' => 'Doom', 'kind' => 'counter', 'min' => 5, 'max' => 1, 'initial' => 1], 'trackers[4]'];
        yield 'POST a Scene Type naming an unknown oracle' => ['POST /mine/narrative/scene-types', ['key' => 'chase', 'name' => 'Chase', 'purpose' => 'Run.', 'oracles' => [], 'setup' => [], 'play' => [['key' => 'ask', 'kind' => 'oracle', 'title' => 'Ask', 'oracle' => 'nowhere']], 'closing' => []], 'sceneTypes[5].play[0]'];
        yield 'PUT a flow selecting an unknown Scene Type' => ['PUT /mine/narrative/flows/quick', ['key' => 'quick', 'name' => 'Quick', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['nowhere']]]]], 'flows[1].phases[0]'];
    }

    /**
     * @param array<string, mixed> $item
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidItems')]
    public function anItemBreakingTheReleaseRulesIsUnprocessableWithItsPath(string $request, array $item, string $path): void
    {
        [$method, $uri] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();

        $this->client->jsonRequest($method, self::BASE.$uri, ['expectedVersion' => 1, 'item' => $item]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($path, $this->error());
        self::assertSame($before, $this->narrative());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, string}>
     */
    public static function itemsInUse(): iterable
    {
        yield 'deleting a tracker a flow lists' => ['DELETE /mine/narrative/trackers/chaos?expectedVersion=1', null, 'flows[0].trackers'];
        yield 'deleting a Scene Type a phase selects' => ['DELETE /mine/narrative/scene-types/getaway?expectedVersion=1', null, 'flows[0].phases[2].selection'];
        yield 'renaming a Scene Type a table entry names' => ['PUT /mine/narrative/scene-types/getaway', ['rename' => 'escape'], 'oracles.tables[1].entries[3]'];
        yield 'renaming a tracker a flow lists' => ['PUT /mine/narrative/trackers/chaos', ['rename' => 'disorder'], 'oracles.likelihood[0].chaos'];
    }

    /**
     * @param array{rename: string}|null $rename
     */
    #[Test]
    #[DataProvider('itemsInUse')]
    public function anItemTheDraftUsesCannotBeDeletedOrRenamedAndTheResponseListsWhere(string $request, ?array $rename, string $path): void
    {
        [$method, $uri] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->narrative();
        $body = [];
        if (null !== $rename) {
            $section = str_contains($uri, 'trackers') ? 'trackers.2' : 'sceneTypes.3';
            $body = ['expectedVersion' => 1, 'item' => [...(array) self::at($before, $section.'.definition'), 'key' => $rename['rename']]];
        }

        $this->client->jsonRequest($method, self::BASE.$uri, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($path, (array) self::at($this->json(), 'paths'));
        self::assertStringContainsString($path, $this->error());
        self::assertSame($before, $this->narrative());
    }

    #[Test]
    public function aVersion1DraftRefusesEveryNarrativeCommand(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');
        $before = $this->narrative('journal');

        foreach ([
            ['POST', '/journal/narrative/tags', ['expectedVersion' => 1, 'item' => $this->tag()]],
            ['PUT', '/journal/narrative/tags/gang', ['expectedVersion' => 1, 'item' => $this->tag()]],
            ['DELETE', '/journal/narrative/flows/heist?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(422, $method);
            self::assertStringStartsWith('schemaVersion', $this->error());
        }
        self::assertSame($before, $this->narrative('journal'));
    }

    /**
     * @return array<string, mixed>
     */
    private function tag(): array
    {
        return ['key' => 'gang', 'label' => 'Gang'];
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
     * Publishes the fixture (minus the dotted paths in $without) and copies it as the signed-in user's draft $key.
     *
     * @param list<string> $without
     */
    private function draft(string $key, string $fixture = 'valid/v2-every-part', array $without = []): void
    {
        $release = ReleaseArrays::fixture($fixture);
        foreach ($without as $path) {
            $release = ReleaseArrays::without($release, $path);
        }
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9%03d', ++$this->releases), $release, false));
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key, 'source' => ['key' => self::at($release, 'gameSystem.key')]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<mixed>
     */
    private function narrative(string $key = 'mine'): array
    {
        $this->client->request('GET', self::BASE.'/'.$key.'/narrative');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<string> the keys of one section of a read of the narrative
     */
    private function keys(mixed $narrative, string $section): array
    {
        $keys = [];
        foreach ((array) self::at($narrative, $section) as $item) {
            $key = self::at($item, 'definition.key');
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
