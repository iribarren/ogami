<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\CreateLibraryPackRequest;
use App\Studio\Infrastructure\Http\LibraryPackCatalogResponse;
use App\Studio\Infrastructure\Http\LibraryPackController;
use App\Studio\Infrastructure\Http\LibraryPackSummaryResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The library over the Studio API (ADR 0033): every game manager lists and reads every pack, only a
 * pack's author edits it, and a pack refuses what only a GameSystem has with a 409.
 */
#[CoversClass(LibraryPackController::class)]
#[CoversClass(CreateLibraryPackRequest::class)]
#[CoversClass(LibraryPackSummaryResponse::class)]
#[CoversClass(LibraryPackCatalogResponse::class)]
final class LibraryPackApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string PACKS = '/api/studio/library/packs';
    private const string GAME_SYSTEMS = '/api/studio/game-systems';

    private KernelBrowser $client;
    private int $version = 1;

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
        yield 'list' => ['GET', self::PACKS];
        yield 'create' => ['POST', self::PACKS];
        yield 'catalog' => ['GET', self::PACKS.'/generic'];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function noSessionIsUnauthorized(string $method, string $path): void
    {
        $this->client->jsonRequest($method, $path, []);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function onlyAGameManagerEntersTheLibrary(string $method, string $path): void
    {
        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);

            $this->client->jsonRequest($method, $path, ['key' => 'generic', 'name' => 'Generic']);

            self::assertResponseStatusCodeSame(403);
        }
    }

    #[Test]
    public function aReadThatFailsAfterAWriteRollsTheWriteBack(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
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
                    $this->entityManager->getConnection()->executeStatement("UPDATE studio_gamesystem SET document = '\"corrupt\"'::jsonb WHERE game_system_key = 'generic'");
                    $this->entityManager->clear();
                }
            }
        };
        $entityManager->getEventManager()->addEventListener(Events::postFlush, $listener);

        $this->client->catchExceptions(false);
        $failed = false;
        try {
            $this->client->jsonRequest('POST', self::PACKS, ['key' => 'generic', 'name' => 'Generic']);
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertGreaterThan(0, $listener->fired, 'The write was flushed before the read failed.');
        self::assertTrue($failed, 'The corrupted document makes the read fail.');
        $this->assertNoPacks();
    }

    #[Test]
    public function aBlankPackIsCreatedAsAGameSystemViewOfKindLibraryPack(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::PACKS, ['key' => 'generic', 'name' => 'Generic', 'description' => 'Reusable content.']);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location', self::PACKS.'/generic');
        $created = $this->json();
        self::assertSame(
            ['generic', 'Generic', 'Reusable content.', 'library-pack', 2, null],
            [$created['key'], $created['name'], $created['description'], $created['kind'], $created['schemaVersion'], $created['latestReleaseVersion']],
        );

        $this->client->request('GET', self::GAME_SYSTEMS.'/generic');
        self::assertResponseIsSuccessful();
        self::assertSame($created, $this->json());

        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->request('GET', self::GAME_SYSTEMS.'/generic');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aGameSystemReadsAsKindGameSystemAndThePackIsNotInTheGameSystemList(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, ['key' => 'my-system', 'name' => 'My system']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('game-system', $this->json()['kind']);
        $this->createPack('generic');

        $this->client->request('GET', self::GAME_SYSTEMS.'/my-system');
        self::assertSame('game-system', $this->json()['kind']);

        $this->client->request('GET', self::GAME_SYSTEMS);
        self::assertSame(['my-system'], array_column($this->json(), 'key'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedCreations(): iterable
    {
        yield 'no key' => [['name' => 'Generic']];
        yield 'no name' => [['key' => 'generic']];
        yield 'key not a string' => [['key' => 5, 'name' => 'Generic']];
        yield 'name not a string' => [['key' => 'generic', 'name' => ['Generic']]];
        yield 'description not a string' => [['key' => 'generic', 'name' => 'Generic', 'description' => 5]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedCreations')]
    public function aMalformedCreationIsABadRequest(array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::PACKS, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $this->json());
        $this->assertNoPacks();
    }

    #[Test]
    public function aBodyThatIsNotJsonIsRefused(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->request('POST', self::PACKS, server: ['CONTENT_TYPE' => 'application/json'], content: '{"key": "generic"');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', self::PACKS, server: ['CONTENT_TYPE' => 'application/json'], content: '["generic"]');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', self::PACKS, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'key=generic');
        self::assertResponseStatusCodeSame(415);
        $this->assertNoPacks();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'bad key' => [['key' => 'Bad Key', 'name' => 'Generic'], 'key'];
        yield 'blank key' => [['key' => '', 'name' => 'Generic'], 'key'];
        yield 'blank name' => [['key' => 'generic', 'name' => '  '], 'name'];
        yield 'long description' => [['key' => 'generic', 'name' => 'Generic', 'description' => str_repeat('x', 100000)], 'description'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidCreations')]
    public function anInvalidCreationIsUnprocessable(array $body, string $mentions): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::PACKS, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($mentions, $this->error());
        $this->assertNoPacks();
    }

    #[Test]
    public function aKeyUsedByAGameSystemAPackOrAReleaseIsAConflict(): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseArrays::fixture('valid/v2-minimal'), false));
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, ['key' => 'my-system', 'name' => 'My system']);
        $this->createPack('generic');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['my-system', 'generic', 'minimal'] as $key) {
            $this->client->jsonRequest('POST', self::PACKS, ['key' => $key, 'name' => 'Again']);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(\sprintf('The GameSystem key "%s" is already taken.', $key), $this->error());
        }
    }

    #[Test]
    public function theLibraryListsEveryPackMineFirstThenByNameWithItemCounts(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createPack('zeta', 'Zeta', 'Ann\'s pack.');
        $this->createPack('generic', 'Generic');
        $this->addItem('generic', 'narrative/tags', 'item', ['key' => 'social', 'label' => 'Social']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, ['key' => 'my-system', 'name' => 'My system']);
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->createPack('alpha', 'Alpha');

        $this->client->request('GET', self::PACKS);

        self::assertResponseIsSuccessful();
        $packs = $this->json();
        self::assertIsArray($packs[0]);
        self::assertIsArray($packs[1]);
        self::assertSame(['alpha', 'generic', 'zeta'], array_column($packs, 'key'));
        self::assertSame([true, false, false], array_column($packs, 'mine'));
        self::assertSame([null, null, 'Ann\'s pack.'], array_column($packs, 'description'));
        self::assertSame(['oracleTables' => 0, 'likelihoodOracles' => 0, 'trackers' => 0, 'tags' => 1, 'factSlots' => 0, 'sceneTypes' => 0], $packs[1]['itemCounts']);
        self::assertSame(['key', 'name', 'description', 'mine', 'itemCounts', 'updatedAt'], array_keys($packs[0]));
    }

    #[Test]
    public function aGameManagerWithoutPacksSeesAnEmptyLibrary(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->assertNoPacks();
    }

    #[Test]
    public function theCatalogListsTheItemsBySectionWithTheirDirectDependenciesForAnyGameManager(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createPack('generic', 'Generic', 'Reusable.');
        $this->addItem('generic', 'narrative/tags', 'item', ['key' => 'social', 'label' => 'Social']);
        $this->addItem('generic', 'narrative/trackers', 'item', ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6]);
        $this->addItem('generic', 'narrative/trackers', 'item', ['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5]);
        $this->addItem('generic', 'narrative/fact-slots', 'item', ['key' => 'secret', 'label' => 'Secret', 'type' => 'text']);
        $this->addItem('generic', 'oracle-tables', 'table', ['key' => 'names', 'name' => 'Names', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]]);
        $this->addItem('generic', 'likelihood-oracles', 'oracle', ['key' => 'fate', 'name' => 'Fate', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5, 'tracker' => 'chaos'], 'exceptionalPercent' => 20]);
        $this->addItem('generic', 'narrative/scene-types', 'item', [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['names'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'trouble', 'kind' => 'choice', 'title' => 'Trouble?', 'options' => [
                ['key' => 'yes', 'label' => 'Yes', 'effects' => [
                    ['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1],
                    ['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']],
                    ['kind' => 'fillFact', 'slot' => 'secret'],
                ]],
                ['key' => 'no', 'label' => 'No'],
            ], 'skip' => 'no']],
        ]);

        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->request('GET', self::PACKS.'/generic');

        self::assertResponseIsSuccessful();
        $catalog = $this->json();
        self::assertSame(['generic', 'Generic', 'Reusable.', false], [$catalog['key'], $catalog['name'], $catalog['description'], $catalog['mine']]);
        self::assertSame(['key', 'name', 'description', 'mine', 'updatedAt', 'sections'], array_keys($catalog));
        self::assertIsArray($catalog['sections']);
        $sections = array_column($catalog['sections'], 'items', 'section');
        self::assertSame(['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'], array_keys($sections));
        self::assertSame(
            [['key' => 'names', 'label' => 'Names', 'kind' => 'table', 'dependsOn' => []], ['key' => 'fate', 'label' => 'Fate', 'kind' => 'likelihood', 'dependsOn' => [['section' => 'trackers', 'key' => 'chaos']]]],
            $sections['oracles'],
        );
        self::assertSame([['key' => 'alarm', 'label' => 'Alarm', 'kind' => null, 'dependsOn' => []], ['key' => 'chaos', 'label' => 'Chaos', 'kind' => null, 'dependsOn' => []]], $sections['trackers']);
        self::assertSame([['key' => 'social', 'label' => 'Social', 'kind' => null, 'dependsOn' => []]], $sections['tags']);
        self::assertSame(
            [['key' => 'tavern', 'label' => 'Tavern', 'kind' => null, 'dependsOn' => [
                ['section' => 'oracles', 'key' => 'names'],
                ['section' => 'trackers', 'key' => 'alarm'],
                ['section' => 'tags', 'key' => 'social'],
                ['section' => 'fact-slots', 'key' => 'secret'],
            ]]],
            $sections['scene-types'],
        );

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->client->request('GET', self::PACKS.'/generic');
        self::assertTrue($this->json()['mine']);
    }

    #[Test]
    public function anEmptyPackHasEverySectionWithoutItems(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createPack('generic');

        $this->client->request('GET', self::PACKS.'/generic');

        self::assertSame([['section' => 'oracles', 'items' => []], ['section' => 'trackers', 'items' => []], ['section' => 'tags', 'items' => []], ['section' => 'fact-slots', 'items' => []], ['section' => 'scene-types', 'items' => []]], $this->json()['sections']);
    }

    #[Test]
    public function anUnknownKeyAndAGameSystemAreNotPacks(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, ['key' => 'my-system', 'name' => 'My system']);

        foreach (['nowhere', 'my-system'] as $key) {
            $this->client->request('GET', self::PACKS.'/'.$key);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString($key, $this->error());
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}> method, path under the pack, body, the refused operation
     */
    public static function refusedOperations(): iterable
    {
        $flow = ['key' => 'quick', 'name' => 'Quick', 'steps' => []];
        $field = ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'];
        $layout = ['columns' => 2, 'groups' => [['name' => 'Stats', 'fields' => ['luck']]]];
        yield 'create flow' => ['POST', '/narrative/flows', ['item' => $flow], 'save a flow'];
        yield 'replace flow' => ['PUT', '/narrative/flows/quick', ['item' => $flow], 'save a flow'];
        yield 'delete flow' => ['DELETE', '/narrative/flows/quick?expectedVersion=1', [], 'delete a flow'];
        yield 'create sheet field' => ['POST', '/sheet/fields', ['field' => $field], 'save a sheet field'];
        yield 'replace sheet field' => ['PUT', '/sheet/fields/luck', ['field' => $field], 'save a sheet field'];
        yield 'delete sheet field' => ['DELETE', '/sheet/fields/luck?expectedVersion=1', [], 'delete a sheet field'];
        yield 'move sheet field' => ['POST', '/sheet/fields/luck/move', ['index' => 0], 'move a sheet field'];
        yield 'save sheet layout' => ['PUT', '/sheet/layout', $layout, 'save a sheet layout'];
        yield 'declare sheet layout' => ['POST', '/sheet/layout/declare', [], 'declare a sheet layout'];
        yield 'remove sheet layout' => ['DELETE', '/sheet/layout?expectedVersion=1', [], 'remove a sheet layout'];
        yield 'create check' => ['POST', '/checks', ['check' => ['key' => 'luck', 'name' => 'Luck']], 'save a check'];
        yield 'replace check' => ['PUT', '/checks/luck', ['check' => ['key' => 'luck', 'name' => 'Luck']], 'save a check'];
        yield 'delete check' => ['DELETE', '/checks/luck?expectedVersion=1', [], 'delete a check'];
        yield 'move check' => ['POST', '/checks/luck/move', ['index' => 0], 'move a check'];
        yield 'credits' => ['PUT', '/metadata', ['name' => 'Generic', 'credits' => ['notice' => 'Fan content.']], 'have credits'];
        yield 'theme' => ['PUT', '/metadata', ['name' => 'Generic', 'theme' => 'noir'], 'have a theme'];
        yield 'publish' => ['POST', '/releases', [], 'be published'];
        yield 'restore' => ['POST', '/restore', ['version' => 1], 'restore a release'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedOperations')]
    public function aPackRefusesWhatOnlyAGameSystemHasWithAConflictAndStaysAsItWas(string $method, string $path, array $body, string $operation): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createPack('generic');
        $this->client->request('GET', self::GAME_SYSTEMS.'/generic');
        $before = $this->json();

        $this->client->jsonRequest($method, self::GAME_SYSTEMS.'/generic'.$path, ['expectedVersion' => 1, ...$body]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(\sprintf('The library pack "generic" cannot %s; a pack holds oracles, trackers, tags, fact slots and Scene Types only.', $operation), $this->error());
        $this->client->request('GET', self::GAME_SYSTEMS.'/generic');
        self::assertSame($before, $this->json());
    }

    #[Test]
    public function aPackKeepsItsOraclesAndNarrativeItemsEditableByItsOwnerOnly(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createPack('generic');
        $this->addItem('generic', 'narrative/tags', 'item', ['key' => 'social', 'label' => 'Social']);

        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/generic/narrative/tags', ['expectedVersion' => 2, 'item' => ['key' => 'gang', 'label' => 'Gang']]);

        self::assertResponseStatusCodeSame(404);
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

    private function createPack(string $key, ?string $name = null, ?string $description = null): void
    {
        $this->version = 1;
        $this->client->jsonRequest('POST', self::PACKS, ['key' => $key, 'name' => $name ?? ucfirst($key), 'description' => $description]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Saves one item of the pack through the owner's editor endpoints, which a pack shares with a GameSystem.
     *
     * @param array<string, mixed> $item
     */
    private function addItem(string $pack, string $path, string $field, array $item): void
    {
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/'.$pack.'/'.$path, ['expectedVersion' => $this->version++, $field => $item]);
        self::assertResponseStatusCodeSame(201);
    }

    private function assertNoPacks(): void
    {
        $this->client->request('GET', self::PACKS);
        self::assertSame([], $this->json());
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
