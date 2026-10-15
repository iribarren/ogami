<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Infrastructure\Http\CreateGameSystemRequest;
use App\Studio\Infrastructure\Http\EditGameSystemMetadataRequest;
use App\Studio\Infrastructure\Http\GameSystemController;
use App\Studio\Infrastructure\Http\GameSystemCreditsResponse;
use App\Studio\Infrastructure\Http\GameSystemResponse;
use App\Studio\Infrastructure\Http\GameSystemSummaryResponse;
use App\Studio\Infrastructure\Http\GameSystemValidationResponse;
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
 * GameSystem drafts over the Studio API. Every endpoint is for game managers only, and a draft is
 * visible to its owner only: another user's key is not found, never forbidden.
 */
#[CoversClass(GameSystemController::class)]
#[CoversClass(CreateGameSystemRequest::class)]
#[CoversClass(EditGameSystemMetadataRequest::class)]
#[CoversClass(GameSystemResponse::class)]
#[CoversClass(GameSystemSummaryResponse::class)]
#[CoversClass(GameSystemCreditsResponse::class)]
#[CoversClass(GameSystemValidationResponse::class)]
final class GameSystemDraftApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';

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
    public static function endpoints(): iterable
    {
        yield 'list' => ['GET', self::BASE];
        yield 'create' => ['POST', self::BASE];
        yield 'get' => ['GET', self::BASE.'/mine'];
        yield 'metadata' => ['PUT', self::BASE.'/mine/metadata'];
        yield 'discard' => ['DELETE', self::BASE.'/mine'];
        yield 'validation' => ['GET', self::BASE.'/mine/validation'];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function noSessionIsUnauthorized(string $method, string $path): void
    {
        $this->client->jsonRequest($method, $path, []);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function endpointsOfOtherRoles(): iterable
    {
        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            foreach (self::endpoints() as $name => [$method, $path]) {
                yield $role.' '.$name => [$role, $method, $path];
            }
        }
    }

    #[Test]
    #[DataProvider('endpointsOfOtherRoles')]
    public function onlyAGameManagerEntersStudio(string $role, string $method, string $path): void
    {
        $this->signIn('ada@example.com', [$role]);

        $this->client->jsonRequest($method, $path, ['key' => 'mine', 'name' => 'Mine']);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aBlankDraftIsCreatedAndRead(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::BASE, ['key' => 'my-system', 'name' => 'My system', 'description' => 'Mine.']);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location', self::BASE.'/my-system');
        $created = $this->json();
        self::assertSame(
            ['my-system', 'My system', 'Mine.', null, null, 2, null, true],
            [$created['key'], $created['name'], $created['description'], $created['credits'], $created['theme'], $created['schemaVersion'], $created['latestReleaseVersion'], $created['hasUnpublishedChanges']],
        );
        self::assertSame(
            ['oracleTables' => 0, 'likelihoodOracles' => 0, 'trackers' => 0, 'factSlots' => 0, 'tags' => 0, 'sceneTypes' => 0, 'flows' => 0, 'sheetFields' => 0, 'checks' => 0],
            $created['sectionCounts'],
        );

        $this->client->request('GET', self::BASE.'/my-system');

        self::assertResponseIsSuccessful();
        self::assertSame($created, $this->json());
    }

    #[Test]
    public function aCopyTakesTheLatestOrAChosenVersionAndAGivenDescription(): void
    {
        $this->publishRelease(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-every-part'), 'gameSystem.description', 'First text'));
        $this->publishRelease(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-every-part'), 'gameSystem.description', 'Second text'));
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::BASE, ['key' => 'copy-latest', 'name' => 'Copy latest', 'source' => ['key' => 'every-part']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Second text', $this->json()['description']);
        self::assertGreaterThan(0, $this->sectionCount('sceneTypes'));

        $this->client->jsonRequest('POST', self::BASE, ['key' => 'copy-first', 'name' => 'Copy first', 'source' => ['key' => 'every-part', 'version' => 1]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('First text', $this->json()['description']);

        $this->client->jsonRequest('POST', self::BASE, ['key' => 'copy-mine', 'name' => 'Copy mine', 'description' => 'My text', 'source' => ['key' => 'every-part', 'version' => null]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Copy mine', 'My text', null], [$this->json()['name'], $this->json()['description'], $this->json()['latestReleaseVersion']]);
    }

    private function sectionCount(string $section): int
    {
        $counts = $this->json()['sectionCounts'] ?? null;
        self::assertIsArray($counts);
        $count = $counts[$section] ?? null;
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedCreations(): iterable
    {
        yield 'no key' => [['name' => 'Mine']];
        yield 'no name' => [['key' => 'mine']];
        yield 'key not a string' => [['key' => 5, 'name' => 'Mine']];
        yield 'name not a string' => [['key' => 'mine', 'name' => ['Mine']]];
        yield 'description not a string' => [['key' => 'mine', 'name' => 'Mine', 'description' => 5]];
        yield 'source not an object' => [['key' => 'mine', 'name' => 'Mine', 'source' => 'minimal']];
        yield 'source a list' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['minimal']]];
        yield 'source without key' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['version' => 1]]];
        yield 'source version not an integer' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['key' => 'minimal', 'version' => '1']]];
        yield 'source version a float' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['key' => 'minimal', 'version' => 1.5]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedCreations')]
    public function aMalformedCreationIsABadRequest(array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest('POST', self::BASE, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $this->json());
        $this->assertNoGameSystems();
    }

    #[Test]
    public function aBodyThatIsNotJsonIsRefused(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->request('POST', self::BASE, server: ['CONTENT_TYPE' => 'application/json'], content: '{"key": "mine"');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', self::BASE, server: ['CONTENT_TYPE' => 'application/json'], content: '["mine"]');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', self::BASE, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'key=mine');
        self::assertResponseStatusCodeSame(415);

        $this->client->request('PUT', self::BASE.'/mine/metadata', server: ['CONTENT_TYPE' => 'text/plain'], content: 'name=Mine');
        self::assertResponseStatusCodeSame(415);
        $this->assertNoGameSystems();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'bad key' => [['key' => 'Bad Key', 'name' => 'Mine'], 'key'];
        yield 'blank key' => [['key' => '', 'name' => 'Mine'], 'key'];
        yield 'blank name' => [['key' => 'mine', 'name' => '  '], 'name'];
        yield 'long description' => [['key' => 'mine', 'name' => 'Mine', 'description' => 'x'], 'description'];
        yield 'unknown source' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['key' => 'nothing']], 'nothing'];
        yield 'unknown source version' => [['key' => 'mine', 'name' => 'Mine', 'source' => ['key' => 'minimal', 'version' => 7]], 'v7'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidCreations')]
    public function anInvalidCreationIsUnprocessable(array $body, string $mentions): void
    {
        $this->publishRelease(ReleaseArrays::fixture('valid/v2-minimal'));
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        if ('description' === $mentions) {
            $body['description'] = str_repeat('x', 100000);
        }

        $this->client->jsonRequest('POST', self::BASE, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($mentions, $this->error());
        $this->assertNoGameSystems();
    }

    #[Test]
    public function aKeyUsedByAGameSystemOrAReleaseIsAConflict(): void
    {
        $this->publishRelease(ReleaseArrays::fixture('valid/v2-minimal'));
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['mine', 'minimal'] as $key) {
            $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => 'Again']);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(\sprintf('The GameSystem key "%s" is already taken.', $key), $this->error());
        }

        $this->assertNoGameSystems();
    }

    #[Test]
    public function theListHoldsOnlyMyGameSystemsNewestUpdatedFirst(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('older');
        $this->createDraft('newer');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->createDraft('theirs');
        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);

        $this->client->request('GET', self::BASE);

        self::assertResponseIsSuccessful();
        $list = $this->json();
        self::assertSame(['newer', 'older'], array_column($list, 'key'));
        $newer = $list[0];
        self::assertIsArray($newer);
        self::assertSame(
            ['key' => 'newer', 'name' => 'newer', 'schemaVersion' => 2, 'latestReleaseVersion' => null, 'hasUnpublishedChanges' => true],
            array_diff_key($newer, ['updatedAt' => true]),
        );
        self::assertIsString($newer['updatedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $newer['updatedAt']);
    }

    #[Test]
    public function aGameManagerWithoutGameSystemsGetsAnEmptyList(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);

        $this->client->request('GET', self::BASE);

        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->content());
    }

    #[Test]
    public function anUnknownKeyOrAnotherOwnersKeyIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['mine', 'nothing'] as $key) {
            foreach ([['GET', ''], ['PUT', '/metadata'], ['DELETE', ''], ['GET', '/validation']] as [$method, $suffix]) {
                $this->client->jsonRequest($method, self::BASE.'/'.$key.$suffix, ['name' => 'Taken over']);

                self::assertResponseStatusCodeSame(404, $method.' '.$key.$suffix);
                self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
            }
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('mine', $this->json()['name']);
    }

    #[Test]
    public function theMetadataIsReplacedAsAWhole(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/metadata', [
            'name' => 'Renamed',
            'description' => 'About it.',
            'credits' => ['notice' => 'Fan content.', 'license' => 'CC BY', 'url' => 'https://example.com/license'],
            'theme' => 'noir',
        ]);

        self::assertResponseIsSuccessful();
        $edited = $this->json();
        self::assertSame(
            ['Renamed', 'About it.', ['notice' => 'Fan content.', 'license' => 'CC BY', 'url' => 'https://example.com/license'], 'noir'],
            [$edited['name'], $edited['description'], $edited['credits'], $edited['theme']],
        );

        $this->client->jsonRequest('PUT', self::BASE.'/mine/metadata', ['name' => 'Plain', 'credits' => ['notice' => 'Only a notice.']]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Plain', null, ['notice' => 'Only a notice.', 'license' => null, 'url' => null], null],
            [$this->json()['name'], $this->json()['description'], $this->json()['credits'], $this->json()['theme']],
            'What the body leaves out is cleared.',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedEdits(): iterable
    {
        yield 'no name' => [['description' => 'About it.']];
        yield 'name not a string' => [['name' => 5]];
        yield 'description not a string' => [['name' => 'Mine', 'description' => ['x']]];
        yield 'theme not a string' => [['name' => 'Mine', 'theme' => 5]];
        yield 'credits not an object' => [['name' => 'Mine', 'credits' => 'Fan content.']];
        yield 'credits a list' => [['name' => 'Mine', 'credits' => ['x']]];
        yield 'credits without notice' => [['name' => 'Mine', 'credits' => ['license' => 'CC BY']]];
        yield 'credits notice not a string' => [['name' => 'Mine', 'credits' => ['notice' => 5]]];
        yield 'credits license not a string' => [['name' => 'Mine', 'credits' => ['notice' => 'x', 'license' => 5]]];
        yield 'credits url not a string' => [['name' => 'Mine', 'credits' => ['notice' => 'x', 'url' => 5]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedEdits')]
    public function aMalformedEditIsABadRequestAndChangesNothing(array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/metadata', $body);

        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('mine', $this->json()['name']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEdits(): iterable
    {
        yield 'blank name' => [['name' => ' '], 'name'];
        yield 'unknown theme' => [['name' => 'Renamed', 'theme' => 'pastel'], 'theme'];
        yield 'http url' => [['name' => 'Renamed', 'credits' => ['notice' => 'x', 'url' => 'http://example.com']], 'gameSystem.credits.url'];
        yield 'credits with an unknown member' => [['name' => 'Renamed', 'credits' => ['notice' => 'x', 'author' => 'Me']], 'gameSystem.credits'];
        yield 'credits notice too long' => [['name' => 'Renamed', 'credits' => ['notice' => str_repeat('x', 2001)]], 'gameSystem.credits.notice'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidEdits')]
    public function anInvalidEditIsUnprocessableAndChangesNothing(array $body, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/metadata', $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($path, $this->error());
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame(['mine', null, null], [$this->json()['name'], $this->json()['credits'], $this->json()['theme']]);
    }

    #[Test]
    public function aSchemaVersion1CopyRefusesCreditsAndTheme(): void
    {
        $this->publishRelease(ReleaseArrays::fixture('valid/contract-doc-example'));
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', self::BASE, ['key' => 'v1-copy', 'name' => 'V1 copy', 'source' => ['key' => 'example-journal']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->json()['schemaVersion']);

        $this->client->jsonRequest('PUT', self::BASE.'/v1-copy/metadata', ['name' => 'V1 copy', 'theme' => 'noir']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('theme', $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/v1-copy/metadata', ['name' => 'Renamed']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->json()['name']);
    }

    #[Test]
    public function aDraftSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        // Another request saves the draft after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($entityManager->getConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement("UPDATE studio_gamesystem SET version = version + 1 WHERE game_system_key = 'mine'");
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->client->jsonRequest('PUT', self::BASE.'/mine/metadata', ['name' => 'Renamed']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('mine', $this->error());
        $entityManager->clear();
        $this->client->request('GET', self::BASE.'/mine');
        self::assertSame('mine', $this->json()['name']);
    }

    #[Test]
    public function aNeverPublishedDraftIsDiscarded(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->request('DELETE', self::BASE.'/mine');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        $this->client->request('GET', self::BASE.'/mine');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aGameSystemWithReleasesCannotBeDiscarded(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('minimal');
        $this->publishRelease(ReleaseArrays::fixture('valid/v2-minimal'));

        $this->client->request('DELETE', self::BASE.'/minimal');

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('minimal', $this->error());
        $this->client->request('GET', self::BASE.'/minimal');
        self::assertSame(1, $this->json()['latestReleaseVersion']);
    }

    #[Test]
    public function aValidDraftReportsItsWarningsAndAnInvalidOneItsFirstError(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('mine');

        $this->client->request('GET', self::BASE.'/mine/validation');

        self::assertResponseIsSuccessful();
        $valid = $this->json();
        self::assertTrue($valid['valid']);
        self::assertNull($valid['error']);
        self::assertIsArray($valid['warnings']);

        $repository = self::getContainer()->get(GameSystemRepository::class);
        $draft = $repository->ofKey('mine');
        self::assertNotNull($draft);
        $draft->restore(['schemaVersion' => 2, 'gameSystem' => ['key' => 'mine', 'name' => 'Mine']], new \DateTimeImmutable());
        $repository->save($draft);

        $this->client->request('GET', self::BASE.'/mine/validation');

        self::assertResponseIsSuccessful();
        $invalid = $this->json();
        self::assertFalse($invalid['valid']);
        self::assertSame([], $invalid['warnings']);
        self::assertIsArray($invalid['error']);
        self::assertIsString($invalid['error']['message']);
        self::assertStringStartsWith('oracles: ', $invalid['error']['message']);
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

    /**
     * @param array<mixed> $content
     */
    private function publishRelease(array $content): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9%03d', ++$this->releases), $content, false));
    }

    private function assertNoGameSystems(): void
    {
        $this->client->request('GET', self::BASE);
        self::assertSame('[]', $this->content());
    }

    private function error(): string
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);

        return $error;
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
