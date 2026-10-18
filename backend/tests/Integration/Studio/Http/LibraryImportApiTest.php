<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\ImportLibraryItemsRequest;
use App\Studio\Infrastructure\Http\LibraryImportController;
use App\Studio\Infrastructure\Http\LibraryImportPreviewResponse;
use App\Studio\Infrastructure\Http\LibraryImportResponse;
use App\Studio\Infrastructure\Http\LibraryImportsResponse;
use App\Studio\Infrastructure\Http\LibraryItemRefRequest;
use App\Studio\Infrastructure\Http\PreviewLibraryImportRequest;
use App\Studio\Infrastructure\Http\UpdateLibraryImportRequest;
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
 * Importing from the library over the Studio API (ADR 0033): Ann's pack "generic" holds the tag
 * social, the oracle table names and the Scene Type tavern, which needs both. Bob's draft "my-game"
 * imports from it.
 */
#[CoversClass(LibraryImportController::class)]
#[CoversClass(ImportLibraryItemsRequest::class)]
#[CoversClass(PreviewLibraryImportRequest::class)]
#[CoversClass(UpdateLibraryImportRequest::class)]
#[CoversClass(LibraryItemRefRequest::class)]
#[CoversClass(LibraryImportsResponse::class)]
#[CoversClass(LibraryImportResponse::class)]
#[CoversClass(LibraryImportPreviewResponse::class)]
final class LibraryImportApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string GAME_SYSTEMS = '/api/studio/game-systems';
    private const string IMPORTS = '/api/studio/game-systems/my-game/library-imports';

    private KernelBrowser $client;
    private int $packVersion = 1;
    private int $releases = 0;

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
        yield 'list' => ['GET', self::IMPORTS];
        yield 'preview' => ['POST', self::IMPORTS.'/preview'];
        yield 'import' => ['POST', self::IMPORTS];
        yield 'update' => ['POST', self::IMPORTS.'/tags/social/update'];
        yield 'detach' => ['DELETE', self::IMPORTS.'/tags/social?expectedVersion=1'];
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
    public function onlyAGameManagerImports(string $method, string $path): void
    {
        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);

            $this->client->jsonRequest($method, $path, []);

            self::assertResponseStatusCodeSame(403);
        }
    }

    #[Test]
    public function aFreshDraftListsNoImportsAndASchemaVersion1DraftNeither(): void
    {
        $this->library();
        $this->draft('legacy', 'valid/contract-doc-example');

        self::assertSame(['version' => 1, 'imports' => []], $this->imports());
        $this->client->request('GET', self::GAME_SYSTEMS.'/legacy/library-imports');
        self::assertResponseIsSuccessful();
        self::assertSame(['version' => 1, 'imports' => []], $this->json());
    }

    #[Test]
    public function importingASceneTypeCopiesWhatItNeedsAndAnswersWithTheImportsAndTheNewVersion(): void
    {
        $this->library();

        $this->client->jsonRequest('POST', self::IMPORTS, ['expectedVersion' => 1, 'pack' => 'generic', 'items' => [['section' => 'scene-types', 'key' => 'tavern']]]);

        self::assertResponseIsSuccessful();
        $expected = [
            'version' => 2,
            'imports' => [
                ['section' => 'oracles', 'key' => 'names', 'label' => 'Names', 'pack' => ['key' => 'generic', 'name' => 'Generic'], 'status' => 'current', 'editedLocally' => false],
                ['section' => 'tags', 'key' => 'social', 'label' => 'Social', 'pack' => ['key' => 'generic', 'name' => 'Generic'], 'status' => 'current', 'editedLocally' => false],
                ['section' => 'scene-types', 'key' => 'tavern', 'label' => 'Tavern', 'pack' => ['key' => 'generic', 'name' => 'Generic'], 'status' => 'current', 'editedLocally' => false],
            ],
        ];
        self::assertSame($expected, $this->json());
        self::assertSame($expected, $this->imports());
        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game/narrative');
        self::assertSame(['social'], array_column((array) $this->json()['tags'], 'key'));
        self::assertSame(['tavern'], array_column((array) $this->json()['sceneTypes'], 'key'));
    }

    #[Test]
    public function aNeededItemTheDraftHasIsKeptAndNotListedAsImported(): void
    {
        $this->library();
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/my-game/narrative/tags', ['expectedVersion' => 1, 'item' => ['key' => 'social', 'label' => 'My social']]);

        $this->client->jsonRequest('POST', self::IMPORTS, ['expectedVersion' => 2, 'pack' => 'generic', 'items' => [['section' => 'scene-types', 'key' => 'tavern']]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['oracles/names', 'scene-types/tavern'], $this->ids($this->json()));
        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game/narrative');
        self::assertSame(['My social'], array_column(array_column((array) $this->json()['tags'], 'definition'), 'label'));
    }

    #[Test]
    public function aPreviewShowsImportsOverridesAndConflictsAndWritesNothing(): void
    {
        $this->library();
        $this->importItem('oracles', 'names');
        $before = $this->imports();

        $this->client->jsonRequest('POST', self::IMPORTS.'/preview', ['pack' => 'generic', 'items' => [['section' => 'scene-types', 'key' => 'tavern'], ['section' => 'oracles', 'key' => 'names']]]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['imports' => [['section' => 'tags', 'key' => 'social'], ['section' => 'scene-types', 'key' => 'tavern']], 'overrides' => [], 'conflicts' => [['section' => 'oracles', 'key' => 'names']], 'error' => null],
            $this->json(),
        );

        $this->client->jsonRequest('POST', self::IMPORTS.'/preview', ['pack' => 'generic', 'items' => [['section' => 'scene-types', 'key' => 'tavern']]]);
        self::assertSame([['section' => 'oracles', 'key' => 'names']], $this->json()['overrides']);
        self::assertSame($before, $this->imports());
    }

    #[Test]
    public function aPreviewReportsTheItemRuleTheResultWouldBreakAndAnImportIsUnprocessable(): void
    {
        $this->library();
        $this->draft('broken', 'valid/v2-every-part');
        // A draft may be invalid while in progress: this one's Scene Type names a missing tracker.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE studio_gamesystem SET document = replace(document::text, '\"tracker\": \"edge\"', '\"tracker\": \"missing\"')::jsonb WHERE game_system_key = 'broken'",
        );
        $path = self::GAME_SYSTEMS.'/broken/library-imports';

        $this->client->jsonRequest('POST', $path.'/preview', ['pack' => 'generic', 'items' => [['section' => 'tags', 'key' => 'social']]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['section' => 'tags', 'key' => 'social']], $this->json()['imports']);
        $error = $this->json()['error'];
        self::assertIsArray($error);
        self::assertIsString($error['path']);
        self::assertStringStartsWith('sceneTypes[0]', $error['path']);
        self::assertNotSame('', $error['message']);

        $this->client->jsonRequest('POST', $path, ['expectedVersion' => 1, 'pack' => 'generic', 'items' => [['section' => 'tags', 'key' => 'social']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('sceneTypes[0]', $this->error());
        $this->client->request('GET', $path);
        self::assertSame(['version' => 1, 'imports' => []], $this->json());
    }

    #[Test]
    public function aPackItemThatChangedOrWentAndALocalEditShowInTheList(): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $this->importItem('oracles', 'names', 2);
        $this->as('ann');
        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/generic/narrative/tags/social', ['expectedVersion' => $this->packVersion++, 'item' => ['key' => 'social', 'label' => 'Social v2']]);
        self::assertResponseIsSuccessful();
        $this->deleteFromPack('narrative/scene-types/tavern');
        $this->deleteFromPack('oracle-tables/names');
        $this->as('bob');

        self::assertSame([['tags/social', 'changed', false], ['oracles/names', 'removed', false]], $this->statuses());

        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/my-game/narrative/tags/social', ['expectedVersion' => 3, 'item' => ['key' => 'social', 'label' => 'Mine']]);
        self::assertResponseIsSuccessful();

        self::assertSame([['tags/social', 'changed', true], ['oracles/names', 'removed', false]], $this->statuses());
    }

    #[Test]
    public function theDraftListAndDetailCountTheImportsThatChangedOrWentAway(): void
    {
        $this->library();
        self::assertSame([0, 0], $this->libraryChanges());
        $this->importItem('scene-types', 'tavern');
        self::assertSame([0, 0], $this->libraryChanges());

        $this->as('ann');
        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/generic/narrative/tags/social', ['expectedVersion' => $this->packVersion++, 'item' => ['key' => 'social', 'label' => 'Social v2']]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', self::GAME_SYSTEMS.'/generic');
        self::assertSame(0, $this->json()['libraryChanges']);
        $this->as('bob');
        self::assertSame([1, 1], $this->libraryChanges());

        // A local edit alone is no library change: the pack's names table did not change.
        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/my-game/oracle-tables/names', ['expectedVersion' => 2, 'table' => ['key' => 'names', 'name' => 'Mine', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Bram']]]]);
        self::assertResponseIsSuccessful();
        self::assertSame([1, 1], $this->libraryChanges());

        $this->as('ann');
        $this->deleteFromPack('narrative/scene-types/tavern');
        $this->as('bob');
        self::assertSame([2, 2], $this->libraryChanges());

        $this->as('ann');
        $this->deleteFromPack('oracle-tables/names');
        $this->as('bob');
        self::assertSame([3, 3], $this->libraryChanges());

        $this->client->jsonRequest('DELETE', self::IMPORTS.'/tags/social?expectedVersion=3');
        self::assertResponseIsSuccessful();
        self::assertSame([2, 2], $this->libraryChanges());
    }

    #[Test]
    public function theReleaseHistoryListsTheLibrarySourcesOfEachReleaseAndNullWhenThereAreNone(): void
    {
        $this->library();
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/my-game/releases', []);
        self::assertResponseStatusCodeSame(201);
        $this->importItem('scene-types', 'tavern', 1);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/my-game/releases', ['notes' => 'With the tavern.']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game/releases');

        self::assertResponseIsSuccessful();
        self::assertSame([2, 1], array_column($this->json(), 'version'));
        self::assertSame([
            [['section' => 'oracles', 'key' => 'names', 'pack' => 'generic'], ['section' => 'tags', 'key' => 'social', 'pack' => 'generic'], ['section' => 'scene-types', 'key' => 'tavern', 'pack' => 'generic']],
            null,
        ], array_column($this->json(), 'librarySources'));
    }

    #[Test]
    public function updatingFromTheLibraryCopiesThePackItemAgainAndBumpsTheVersion(): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $this->as('ann');
        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/generic/narrative/tags/social', ['expectedVersion' => $this->packVersion++, 'item' => ['key' => 'social', 'label' => 'Social v2']]);
        $this->as('bob');

        $this->client->jsonRequest('POST', self::IMPORTS.'/tags/social/update', ['expectedVersion' => 2]);

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['version']);
        self::assertSame([['tags/social', 'current', false]], $this->statuses());
        self::assertSame(['Social v2'], array_column((array) $this->json()['imports'], 'label'));
    }

    #[Test]
    public function detachingForgetsTheImportAndKeepsTheItem(): void
    {
        $this->library();
        $this->importItem('tags', 'social');

        $this->client->jsonRequest('DELETE', self::IMPORTS.'/tags/social?expectedVersion=2');

        self::assertResponseIsSuccessful();
        self::assertSame(['version' => 3, 'imports' => []], $this->json());
        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game/narrative');
        self::assertSame(['social'], array_column((array) $this->json()['tags'], 'key'));
    }

    #[Test]
    public function anImportThatDoesNotExistIsNotFound(): void
    {
        $this->library();

        $this->client->jsonRequest('POST', self::IMPORTS.'/tags/social/update', ['expectedVersion' => 1]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('social', $this->error());

        $this->client->jsonRequest('DELETE', self::IMPORTS.'/tags/social?expectedVersion=1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['version' => 1, 'imports' => []], $this->imports());
    }

    #[Test]
    public function aPackItemThatIsGoneRefusesTheUpdateWithAConflict(): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $this->as('ann');
        $this->deleteFromPack('narrative/scene-types/tavern');
        $this->deleteFromPack('narrative/tags/social');
        $this->as('bob');

        $this->client->jsonRequest('POST', self::IMPORTS.'/tags/social/update', ['expectedVersion' => 2]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('generic', $this->error());
        self::assertSame(2, $this->imports()['version']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}> body, status, a text the error holds
     */
    public static function refusedImports(): iterable
    {
        $tag = ['section' => 'tags', 'key' => 'social'];
        yield 'unknown pack' => [['pack' => 'nowhere', 'items' => [$tag]], 404, 'nowhere'];
        yield 'a GameSystem is not a pack' => [['pack' => 'other-game', 'items' => [$tag]], 404, 'other-game'];
        yield 'unknown item' => [['pack' => 'generic', 'items' => [['section' => 'trackers', 'key' => 'chaos']]], 404, 'chaos'];
        yield 'item the draft has' => [['pack' => 'generic', 'items' => [$tag, ['section' => 'oracles', 'key' => 'names']]], 409, 'tag "social", oracle "names"'];
        yield 'no items' => [['pack' => 'generic'], 422, 'items'];
        yield 'empty items' => [['pack' => 'generic', 'items' => []], 422, 'items'];
        yield 'items not a list' => [['pack' => 'generic', 'items' => $tag], 422, 'items'];
        yield 'unknown section' => [['pack' => 'generic', 'items' => [['section' => 'flows', 'key' => 'quick']]], 422, 'items[0].section'];
        yield 'key not a text' => [['pack' => 'generic', 'items' => [['section' => 'tags', 'key' => 5]]], 422, 'items[0].key'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedImports')]
    public function aRefusedImportAndPreviewChangeNothing(array $body, int $status, string $mentions): void
    {
        $this->library();
        $this->draft('other-game');
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/my-game/narrative/tags', ['expectedVersion' => 1, 'item' => ['key' => 'social', 'label' => 'Mine']]);
        $this->importItem('oracles', 'names', 2);
        $before = $this->imports();
        $draft = $this->document();

        $this->client->jsonRequest('POST', self::IMPORTS, ['expectedVersion' => 3, ...$body]);

        self::assertResponseStatusCodeSame($status);
        self::assertStringContainsString($mentions, $this->error());
        self::assertSame($before, $this->imports());
        self::assertSame($draft, $this->document());

        if (409 !== $status) {
            $this->client->jsonRequest('POST', self::IMPORTS.'/preview', $body);
            self::assertResponseStatusCodeSame($status);
        }
    }

    #[Test]
    public function aPackIsNotAnImportTarget(): void
    {
        $this->library();
        $this->as('ann');
        $path = self::GAME_SYSTEMS.'/generic/library-imports';
        $items = [['section' => 'tags', 'key' => 'social']];
        $version = $this->packVersion;

        foreach ([
            ['POST', $path, ['expectedVersion' => $version, 'pack' => 'generic', 'items' => $items]],
            ['POST', $path.'/preview', ['pack' => 'generic', 'items' => $items]],
            ['DELETE', $path.'/tags/social?expectedVersion='.$version, []],
        ] as [$method, $url, $body]) {
            $this->client->jsonRequest($method, $url, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$url);
            self::assertStringContainsString('"generic"', $this->error());
        }

        // A pack imports nothing, so it has no import to update.
        $this->client->jsonRequest('POST', $path.'/tags/social/update', ['expectedVersion' => $version]);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesImportAndPreview(): void
    {
        $this->library();
        $this->draft('legacy', 'valid/contract-doc-example');
        $path = self::GAME_SYSTEMS.'/legacy/library-imports';
        $items = [['section' => 'tags', 'key' => 'social']];

        $this->client->jsonRequest('POST', $path, ['expectedVersion' => 1, 'pack' => 'generic', 'items' => $items]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('schemaVersion', $this->error());

        $this->client->jsonRequest('POST', $path.'/preview', ['pack' => 'generic', 'items' => $items]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('schemaVersion', $this->error());
    }

    #[Test]
    public function aStaleVersionIsAConflictForEveryWrite(): void
    {
        $this->library();
        $this->importItem('tags', 'social');

        foreach ([
            ['POST', self::IMPORTS, ['expectedVersion' => 1, 'pack' => 'generic', 'items' => [['section' => 'oracles', 'key' => 'names']]]],
            ['POST', self::IMPORTS.'/tags/social/update', ['expectedVersion' => 1]],
            ['DELETE', self::IMPORTS.'/tags/social?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, $path, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
        }
        self::assertSame(['tags/social'], $this->ids($this->imports()));
        self::assertSame(2, $this->imports()['version']);
    }

    #[Test]
    public function aConcurrentSaveIsAConflictForEveryWriteAndChangesNothing(): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $before = $this->imports();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Another request saves the draft between this request's load and its flush.
        $entityManager->getEventManager()->addEventListener(Events::onFlush, new readonly class($entityManager->getConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function onFlush(): void
            {
                $this->connection->executeStatement("UPDATE studio_gamesystem SET version = version + 1 WHERE game_system_key = 'my-game'");
            }
        });

        foreach ([
            ['POST', self::IMPORTS, ['pack' => 'generic', 'items' => [['section' => 'oracles', 'key' => 'names']]]],
            ['POST', self::IMPORTS.'/tags/social/update', []],
            ['DELETE', self::IMPORTS.'/tags/social?expectedVersion=%d', []],
        ] as [$method, $path, $body]) {
            $version = $this->imports()['version'];
            self::assertIsInt($version);
            $this->client->jsonRequest($method, \sprintf($path, $version), [...$body, 'expectedVersion' => $version]);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertSame($before['imports'], $this->imports()['imports']);
        }
    }

    #[Test]
    public function anUpdateThatBreaksAnItemRuleIsUnprocessableAndChangesNothing(): void
    {
        $this->library();
        $this->as('ann');
        $this->addItem('narrative/fact-slots', 'item', ['key' => 'secret', 'label' => 'Secret', 'type' => 'text']);
        $this->as('bob');
        $this->importItem('fact-slots', 'secret');
        // The draft's own Scene Type fills the slot with a text, which only a text slot takes.
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/my-game/narrative/scene-types', ['expectedVersion' => 2, 'item' => [
            'key' => 'whisper', 'name' => 'Whisper', 'purpose' => 'Hush.', 'oracles' => [], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'tell', 'kind' => 'choice', 'title' => 'Tell?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'fillFact', 'slot' => 'secret', 'text' => 'Hush']]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ]]);
        self::assertResponseStatusCodeSame(201);
        $this->as('ann');
        $this->client->jsonRequest('PUT', self::GAME_SYSTEMS.'/generic/narrative/fact-slots/secret', ['expectedVersion' => $this->packVersion++, 'item' => ['key' => 'secret', 'label' => 'Secret', 'type' => 'npc']]);
        self::assertResponseIsSuccessful();
        $this->as('bob');
        $imports = $this->imports();
        $document = $this->document();

        $this->client->jsonRequest('POST', self::IMPORTS.'/fact-slots/secret/update', ['expectedVersion' => $imports['version']]);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', $this->error());
        self::assertSame($imports, $this->imports());
        self::assertSame($document, $this->document());
    }

    #[Test]
    public function aReadThatFailsAfterAWriteRollsTheWriteBack(): void
    {
        $this->library();
        $before = $this->imports();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($entityManager) {
            public bool $armed = true;

            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            /**
             * Once the write is flushed, corrupts the stored imports and forgets the loaded draft, so that
             * the read after the write fails.
             */
            public function postFlush(): void
            {
                if ($this->armed) {
                    $this->entityManager->getConnection()->executeStatement("UPDATE studio_gamesystem SET library_imports = '\"corrupt\"'::jsonb WHERE game_system_key = 'my-game'");
                    $this->entityManager->clear();
                }
            }
        };
        $entityManager->getEventManager()->addEventListener(Events::postFlush, $listener);

        $this->client->catchExceptions(false);
        $failed = false;
        try {
            $this->client->jsonRequest('POST', self::IMPORTS, ['expectedVersion' => 1, 'pack' => 'generic', 'items' => [['section' => 'tags', 'key' => 'social']]]);
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertTrue($failed, 'The corrupted imports make the read fail.');
        self::assertSame($before, $this->imports(), 'The write was rolled back with the failed read.');
    }

    #[Test]
    public function anotherGameManagerDoesNotFindTheDraft(): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $this->signIn('cat@example.com', ['GAME_MANAGER']);

        foreach ([
            ['GET', self::IMPORTS, []],
            ['POST', self::IMPORTS.'/preview', ['pack' => 'generic', 'items' => [['section' => 'tags', 'key' => 'social']]]],
            ['POST', self::IMPORTS, ['expectedVersion' => 2, 'pack' => 'generic', 'items' => [['section' => 'oracles', 'key' => 'names']]]],
            ['POST', self::IMPORTS.'/tags/social/update', ['expectedVersion' => 2]],
            ['DELETE', self::IMPORTS.'/tags/social?expectedVersion=2', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, $path, $body);

            self::assertResponseStatusCodeSame(404, $method.' '.$path);
        }
        $this->as('bob');
        self::assertSame(['tags/social'], $this->ids($this->imports()));
        self::assertSame(2, $this->imports()['version']);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function malformedBodies(): iterable
    {
        $items = [['section' => 'tags', 'key' => 'social']];
        yield 'import: no version' => ['POST', '', ['pack' => 'generic', 'items' => $items]];
        yield 'import: text version' => ['POST', '', ['expectedVersion' => '1', 'pack' => 'generic', 'items' => $items]];
        yield 'import: no pack' => ['POST', '', ['expectedVersion' => 1, 'items' => $items]];
        yield 'import: pack not a text' => ['POST', '', ['expectedVersion' => 1, 'pack' => 5, 'items' => $items]];
        yield 'preview: no pack' => ['POST', '/preview', ['items' => $items]];
        yield 'preview: pack not a text' => ['POST', '/preview', ['pack' => ['generic'], 'items' => $items]];
        yield 'update: no version' => ['POST', '/tags/social/update', []];
        yield 'update: text version' => ['POST', '/tags/social/update', ['expectedVersion' => 'one']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $method, string $path, array $body): void
    {
        $this->library();
        $this->importItem('tags', 'social');
        $before = $this->imports();

        $this->client->jsonRequest($method, self::IMPORTS.$path, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $this->json());
        self::assertSame($before, $this->imports());
    }

    #[Test]
    public function aMissingOrMalformedVersionParameterIsABadRequestAndAnUnknownSectionIsNotFound(): void
    {
        $this->library();
        $this->importItem('tags', 'social');

        foreach (['', '?expectedVersion=', '?expectedVersion=one', '?expectedVersion=1.5'] as $query) {
            $this->client->request('DELETE', self::IMPORTS.'/tags/social'.$query);

            self::assertResponseStatusCodeSame(400, $query);
        }

        $this->client->request('DELETE', self::IMPORTS.'/flows/quick?expectedVersion=2');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', self::IMPORTS.'/flows/quick/update', ['expectedVersion' => 2]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['tags/social'], $this->ids($this->imports()));
    }

    #[Test]
    public function aBodyThatIsNotJsonIsRefused(): void
    {
        $this->library();

        foreach (['', '/preview', '/tags/social/update'] as $path) {
            $this->client->request('POST', self::IMPORTS.$path, server: ['CONTENT_TYPE' => 'application/json'], content: '{"pack": "generic"');
            self::assertResponseStatusCodeSame(400, $path);

            $this->client->request('POST', self::IMPORTS.$path, server: ['CONTENT_TYPE' => 'application/json'], content: '["generic"]');
            self::assertResponseStatusCodeSame(400, $path);

            $this->client->request('POST', self::IMPORTS.$path, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'pack=generic');
            self::assertResponseStatusCodeSame(415, $path);
        }
        self::assertSame(['version' => 1, 'imports' => []], $this->imports());
    }

    /**
     * Ann's pack "generic" and Bob's draft "my-game" (signed in as Bob).
     */
    private function library(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', '/api/studio/library/packs', ['key' => 'generic', 'name' => 'Generic']);
        $this->packVersion = 1;
        $this->addItem('narrative/tags', 'item', ['key' => 'social', 'label' => 'Social']);
        $this->addItem('oracle-tables', 'table', ['key' => 'names', 'name' => 'Names', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]]);
        $this->addItem('narrative/scene-types', 'item', [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['names'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'meet', 'kind' => 'choice', 'title' => 'Meet?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']]]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ]);
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, ['key' => 'my-game', 'name' => 'My game']);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->as(strstr($email, '@', true) ?: $email);
    }

    private function as(string $name): void
    {
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $name.'@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function addItem(string $path, string $field, array $item): void
    {
        $this->client->jsonRequest('POST', self::GAME_SYSTEMS.'/generic/'.$path, ['expectedVersion' => $this->packVersion++, $field => $item]);
        self::assertResponseStatusCodeSame(201);
    }

    private function deleteFromPack(string $path): void
    {
        $this->client->jsonRequest('DELETE', self::GAME_SYSTEMS.'/generic/'.$path.'?expectedVersion='.$this->packVersion++);
        self::assertResponseIsSuccessful();
    }

    /**
     * A draft of the signed-in user, copied from a release fixture when one is given.
     */
    private function draft(string $key, ?string $fixture = null): void
    {
        $body = ['key' => $key, 'name' => $key];
        if (null !== $fixture) {
            $release = ReleaseArrays::fixture($fixture);
            self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9%03d', ++$this->releases), $release, false));
            $body['source'] = ['key' => self::at($release, 'gameSystem.key')];
        }

        $this->client->jsonRequest('POST', self::GAME_SYSTEMS, $body);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{int, int} libraryChanges of the draft "my-game" in the list and in its detail
     */
    private function libraryChanges(): array
    {
        $this->client->request('GET', self::GAME_SYSTEMS);
        self::assertResponseIsSuccessful();
        $list = array_column($this->json(), 'libraryChanges', 'key');
        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game');
        self::assertResponseIsSuccessful();
        $inList = $list['my-game'];
        $inDetail = $this->json()['libraryChanges'];
        self::assertIsInt($inList);
        self::assertIsInt($inDetail);

        return [$inList, $inDetail];
    }

    private function importItem(string $section, string $key, int $version = 1): void
    {
        $this->client->jsonRequest('POST', self::IMPORTS, ['expectedVersion' => $version, 'pack' => 'generic', 'items' => [['section' => $section, 'key' => $key]]]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function imports(): array
    {
        $this->client->request('GET', self::IMPORTS);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<array{string, string, bool}> section/key, status and editedLocally of every import
     */
    private function statuses(): array
    {
        /** @var list<array{section: string, key: string, status: string, editedLocally: bool}> $imports */
        $imports = $this->imports()['imports'];

        return array_map(static fn (array $import): array => [$import['section'].'/'.$import['key'], $import['status'], $import['editedLocally']], $imports);
    }

    /**
     * @param array<mixed> $view
     *
     * @return list<string>
     */
    private function ids(array $view): array
    {
        /** @var list<array{section: string, key: string}> $imports */
        $imports = $view['imports'];

        return array_map(static fn (array $import): string => $import['section'].'/'.$import['key'], $imports);
    }

    /**
     * @return array<mixed> the draft's narrative, which an import changes
     *
     * @phpstan-impure
     */
    private function document(): array
    {
        $this->client->request('GET', self::GAME_SYSTEMS.'/my-game/narrative');

        return $this->json();
    }

    /**
     * @param array<mixed> $data
     */
    private function at(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            $data = \is_array($data) ? $data[$segment] ?? null : null;
        }

        return $data;
    }

    private function error(): string
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);

        return $error;
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
