<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\GameSystemSheetController;
use App\Studio\Infrastructure\Http\GameSystemSheetResponse;
use App\Studio\Infrastructure\Http\OracleInUseResponse;
use App\Studio\Infrastructure\Http\SheetGroupResponse;
use App\Studio\Infrastructure\Http\SheetPointBudgetResponse;
use App\Studio\Infrastructure\Http\SheetTrackerResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sheet of a GameSystem draft over the Studio API: the read, the field commands, the layout
 * commands and the point budget commands. Runs against
 * valid/v2-sheet-effects: fields pow, hp, sanity and bonus (derived from pow); pow is read by bonus
 * and by sheet effects, sanity by a check band. Owner only: another user's draft is not found.
 */
#[CoversClass(GameSystemSheetController::class)]
#[CoversClass(GameSystemSheetResponse::class)]
#[CoversClass(SheetGroupResponse::class)]
#[CoversClass(SheetPointBudgetResponse::class)]
#[CoversClass(SheetTrackerResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
final class GameSystemSheetApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const array ROUTES = [
        'read' => ['GET', '/mine/sheet'],
        'create' => ['POST', '/mine/sheet/fields'],
        'replace' => ['PUT', '/mine/sheet/fields/pow'],
        'delete' => ['DELETE', '/mine/sheet/fields/pow?expectedVersion=1'],
        'move' => ['POST', '/mine/sheet/fields/pow/move'],
        'saveLayout' => ['PUT', '/mine/sheet/layout'],
        'declareLayout' => ['POST', '/mine/sheet/layout/declare'],
        'removeLayout' => ['DELETE', '/mine/sheet/layout?expectedVersion=1'],
        'savePointBudget' => ['PUT', '/mine/sheet/point-budget'],
        'removePointBudget' => ['DELETE', '/mine/sheet/point-budget?expectedVersion=1'],
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
        $before = $this->sheet();
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
            $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]);
        } catch (\Throwable) {
            // The failure is not turned into a 404 or 409.
            $failed = true;
        } finally {
            $listener->armed = false;
            $this->client->catchExceptions(true);
        }

        self::assertGreaterThan(0, $listener->fired, 'The write was flushed before the read failed.');
        self::assertTrue($failed, 'The corrupted document makes the read fail.');
        self::assertSame($before, $this->sheet(), 'The write was rolled back with the failed read, version included.');
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
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'field' => $this->luck(), 'index' => 0, 'groups' => [['name' => 'Stats']]]);

            self::assertResponseStatusCodeSame(404, $method.' '.$key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        self::assertSame(1, $this->sheet()['version'], 'Nothing changed.');
    }

    #[Test]
    public function theSheetIsReadWithFieldsTrackersAndThePublishedFieldKeys(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('GET', self::BASE.'/mine/sheet');

        self::assertResponseIsSuccessful();
        $sheet = $this->json();
        self::assertSame([1, 2, 1], [$sheet['version'], $sheet['schemaVersion'], $sheet['columns']]);
        self::assertNull($sheet['groups']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($sheet));
        self::assertEquals(['key' => 'pow', 'label' => 'Power', 'type' => 'number', 'min' => 1, 'max' => 99, 'default' => 50], self::at($sheet, 'fields.0'));
        self::assertSame([['key' => 'dread', 'label' => 'Dread', 'max' => 6]], $sheet['trackers']);
        self::assertSame([], $sheet['publishedFieldKeys'], 'The draft has no release of its own yet.');
    }

    #[Test]
    public function publishedFieldKeysFollowTheLatestReleaseNotTheDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->sheet()['publishedFieldKeys']);

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]);

        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], $this->keys($this->sheet()));
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->sheet()['publishedFieldKeys']);

        $this->client->jsonRequest('POST', self::BASE.'/mine/releases', []);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], $this->sheet()['publishedFieldKeys']);
    }

    #[Test]
    public function declaredGroupsAndColumnsAreRead(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', edit: static function (array $release): array {
            /** @var list<array<string, mixed>> $fields */
            $fields = self::at($release, 'sheet.fields');
            $fields = array_map(static fn (array $field): array => [...$field, 'group' => 'hp' === $field['key'] ? 'Health' : 'Stats'], $fields);

            return ReleaseArrays::with($release, 'sheet', ['columns' => 2, 'groups' => [['name' => 'Stats', 'span' => 2], ['name' => 'Health', 'columns' => 2]], 'fields' => $fields]);
        });

        $sheet = $this->sheet();

        self::assertSame(2, $sheet['columns']);
        self::assertSame([['name' => 'Stats', 'columns' => 1, 'span' => 2], ['name' => 'Health', 'columns' => 2, 'span' => 1]], $sheet['groups']);
    }

    #[Test]
    public function aVersion1DraftReadsAsAnEmptySheet(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $sheet = $this->sheet('journal');

        self::assertSame([1, 1, 1], [$sheet['version'], $sheet['schemaVersion'], $sheet['columns']]);
        self::assertNull($sheet['groups']);
        self::assertSame([[], [], []], [$sheet['fields'], $sheet['trackers'], $sheet['publishedFieldKeys']]);
    }

    #[Test]
    public function aNewFieldIsAppendedAndTheReadModelReturned(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]);

        self::assertResponseStatusCodeSame(201);
        $sheet = $this->json();
        self::assertSame(2, $sheet['version']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], $this->keys($sheet));
        self::assertSame($this->luck(), self::at($sheet, 'fields.4'));
        self::assertEquals($sheet, $this->sheet(), 'The response is the read model.');
    }

    #[Test]
    public function aFieldIsReplacedInPlaceAndRenamedWhenTheBodyHasAnotherKey(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/hp', ['expectedVersion' => 1, 'field' => ['key' => 'hp', 'label' => 'Health', 'type' => 'track', 'max' => 12]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
        self::assertSame('Health', self::at($this->json(), 'fields.1.label'));

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 2, 'field' => $this->luck()]);
        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/luck', ['expectedVersion' => 3, 'field' => [...$this->luck(), 'key' => 'fortune']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['version']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'fortune'], $this->keys($this->json()));
    }

    #[Test]
    public function anUnusedFieldIsDeleted(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]);

        $this->client->request('DELETE', self::BASE.'/mine/sheet/fields/luck?expectedVersion=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->json()['version']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($this->json()));
    }

    #[Test]
    public function aFieldIsMovedToAnIndexOfTheWholeList(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields/pow/move', ['expectedVersion' => 1, 'index' => 3]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
        self::assertSame(['hp', 'sanity', 'bonus', 'pow'], $this->keys($this->json()));
        self::assertEquals($this->json(), $this->sheet());
    }

    #[Test]
    public function aRenamedGroupTakesItsFieldsAlongAndTheWholeSheetIsStored(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', edit: $this->laidOut(...));

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/layout', ['expectedVersion' => 1, 'columns' => 2, 'groups' => [
            ['name' => 'Attributes', 'previousName' => 'Stats', 'columns' => 2, 'span' => 2],
            ['name' => 'Health', 'columns' => 1],
            ['name' => 'Gear'],
        ]]);

        self::assertResponseStatusCodeSame(200);
        $sheet = $this->json();
        self::assertSame(2, $sheet['version']);
        self::assertSame(2, $sheet['columns']);
        self::assertSame([
            ['name' => 'Attributes', 'columns' => 2, 'span' => 2],
            ['name' => 'Health', 'columns' => 1, 'span' => 1],
            ['name' => 'Gear', 'columns' => 1, 'span' => 1],
        ], $sheet['groups']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($sheet));
        self::assertSame(['Attributes', 'Health', 'Health', 'Attributes'], array_column((array) $sheet['fields'], 'group'));
        self::assertSame(2, self::at($sheet, 'fields.0.span'), 'A field keeps its span.');
        self::assertEquals($sheet, $this->sheet(), 'The response is the read model, as stored.');
    }

    #[Test]
    public function declaringTheLayoutGroupsTheFieldsAndRemovingItKeepsTheGroups(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/layout/declare', ['expectedVersion' => 1]);

        self::assertResponseStatusCodeSame(200);
        $sheet = $this->json();
        self::assertSame([2, 1], [$sheet['version'], $sheet['columns']]);
        self::assertSame([['name' => 'General', 'columns' => 1, 'span' => 1]], $sheet['groups']);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($sheet));
        self::assertSame(['General', 'General', 'General', 'General'], array_column((array) $sheet['fields'], 'group'));
        self::assertEquals($sheet, $this->sheet());

        $this->client->request('DELETE', self::BASE.'/mine/sheet/layout?expectedVersion=2');

        self::assertResponseStatusCodeSame(200);
        $sheet = $this->json();
        self::assertSame([3, 1, null], [$sheet['version'], $sheet['columns'], $sheet['groups']]);
        self::assertSame(['General', 'General', 'General', 'General'], array_column((array) $sheet['fields'], 'group'));
        self::assertEquals($sheet, $this->sheet());
    }

    #[Test]
    public function removingALaidOutSheetDropsTheSpansAndColumns(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', edit: $this->laidOut(...));

        $this->client->request('DELETE', self::BASE.'/mine/sheet/layout?expectedVersion=1');

        self::assertResponseStatusCodeSame(200);
        $sheet = $this->json();
        self::assertSame([2, 1, null], [$sheet['version'], $sheet['columns'], $sheet['groups']]);
        self::assertSame(['Stats', 'Health', 'Health', 'Stats'], array_column((array) $sheet['fields'], 'group'));
        self::assertSame([], array_filter((array) $sheet['fields'], static fn (mixed $field): bool => \is_array($field) && \array_key_exists('span', $field)));
        self::assertEquals($sheet, $this->sheet());
    }

    #[Test]
    public function aSheetWithoutAPointBudgetReadsNull(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        self::assertNull($this->sheet()['pointBudget']);
    }

    #[Test]
    public function aPointBudgetIsSavedReplacedAndRemoved(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', edit: $this->withLuck(...));

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/point-budget', ['expectedVersion' => 1, 'label' => 'Points', 'total' => 100, 'fields' => ['pow', 'luck'], 'min' => 1, 'max' => 10]);

        self::assertResponseStatusCodeSame(200);
        $sheet = $this->json();
        self::assertSame(2, $sheet['version']);
        self::assertSame(['label' => 'Points', 'total' => 100, 'fields' => ['pow', 'luck'], 'min' => 1, 'max' => 10], $sheet['pointBudget']);
        self::assertSame($sheet, $this->sheet());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/point-budget', ['expectedVersion' => 2, 'label' => null, 'total' => 50, 'fields' => ['luck'], 'min' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['label' => null, 'total' => 50, 'fields' => ['luck'], 'min' => null, 'max' => null], $this->json()['pointBudget'], 'The budget is replaced whole: what is left out is unset.');
        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], $this->keys($this->json()));

        $this->client->request('DELETE', self::BASE.'/mine/sheet/point-budget?expectedVersion=3');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['version']);
        self::assertNull($this->json()['pointBudget']);
        self::assertNull($this->sheet()['pointBudget']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}> the body without its version and where the 422 points
     */
    public static function invalidBudgets(): iterable
    {
        yield 'no total' => [['fields' => ['pow']], 'sheet.pointBudget.total'];
        yield 'a total that is not a number' => [['total' => 'many', 'fields' => ['pow']], 'sheet.pointBudget.total'];
        yield 'a total of zero' => [['total' => 0, 'fields' => ['pow']], 'sheet.pointBudget.total'];
        yield 'no fields' => [['total' => 100], 'sheet.pointBudget.fields'];
        yield 'fields that are not a list' => [['total' => 100, 'fields' => 'pow'], 'sheet.pointBudget.fields'];
        yield 'an unknown field' => [['total' => 100, 'fields' => ['nowhere']], 'sheet.pointBudget.fields[0]'];
        yield 'a field that is not a number' => [['total' => 100, 'fields' => ['hp']], 'sheet.pointBudget.fields[0]'];
        yield 'a field that is not a key' => [['total' => 100, 'fields' => [7]], 'sheet.pointBudget.fields[0]'];
        yield 'a repeated field' => [['total' => 100, 'fields' => ['pow', 'pow']], 'sheet.pointBudget.fields[1]'];
        yield 'min above max' => [['total' => 100, 'fields' => ['pow'], 'min' => 50, 'max' => 40], 'sheet.pointBudget.max'];
        yield 'a min that is not a number' => [['total' => 100, 'fields' => ['pow'], 'min' => 'low'], 'sheet.pointBudget.min'];
        yield 'a total below the lowest values' => [['total' => 10, 'fields' => ['pow'], 'min' => 20], 'sheet.pointBudget.total'];
        yield 'an unknown property' => [['total' => 100, 'fields' => ['pow'], 'bonus' => 3], 'sheet.pointBudget'];
        yield 'a label that is not text' => [['label' => 5, 'total' => 100, 'fields' => ['pow']], 'sheet.pointBudget.label'];
    }

    /**
     * @param array<string, mixed> $budget
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidBudgets')]
    public function anInvalidPointBudgetIsUnprocessableOnItsPathAndChangesNothing(array $budget, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/point-budget', ['expectedVersion' => 1, ...$budget]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($path, $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function removingAPointBudgetThatIsNotThereIsUnprocessable(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/sheet/point-budget?expectedVersion=1');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('sheet.pointBudget', $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aFieldOfThePointBudgetCannotBeDeletedOrRenamed(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', edit: $this->withLuck(...));
        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/point-budget', ['expectedVersion' => 1, 'total' => 100, 'fields' => ['luck']]);
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/sheet/fields/luck?expectedVersion=2');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sheet.pointBudget.fields[0]'], $this->json()['paths']);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/luck', ['expectedVersion' => 2, 'field' => [...$this->luck(), 'key' => 'fortune']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aLibraryPackRefusesThePointBudgetCommands(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', '/api/studio/library/packs', ['key' => 'names', 'name' => 'Names']);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('PUT', self::BASE.'/names/sheet/point-budget', ['expectedVersion' => 1, 'total' => 100, 'fields' => ['pow']]);
        self::assertResponseStatusCodeSame(409);
        $this->client->request('DELETE', self::BASE.'/names/sheet/point-budget?expectedVersion=1');
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * @return iterable<string, array{string, string|null, mixed}>
     */
    public static function malformedBodies(): iterable
    {
        $field = ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'];

        foreach (['POST' => '/mine/sheet/fields', 'PUT' => '/mine/sheet/fields/pow'] as $method => $path) {
            yield $method.' not a JSON object' => [$method.' '.$path, '[1, 2]', null];
            yield $method.' not JSON' => [$method.' '.$path, '{"field":', null];
            yield $method.' no expectedVersion' => [$method.' '.$path, null, ['field' => $field]];
            yield $method.' expectedVersion a string' => [$method.' '.$path, null, ['expectedVersion' => '1', 'field' => $field]];
            yield $method.' expectedVersion a float' => [$method.' '.$path, null, ['expectedVersion' => 1.5, 'field' => $field]];
            yield $method.' no field' => [$method.' '.$path, null, ['expectedVersion' => 1]];
            yield $method.' field null' => [$method.' '.$path, null, ['expectedVersion' => 1, 'field' => null]];
            yield $method.' field a list' => [$method.' '.$path, null, ['expectedVersion' => 1, 'field' => [$field]]];
            yield $method.' field an empty list' => [$method.' '.$path, null, ['expectedVersion' => 1, 'field' => []]];
            yield $method.' field a string' => [$method.' '.$path, null, ['expectedVersion' => 1, 'field' => 'luck']];
        }

        $move = '/mine/sheet/fields/pow/move';
        yield 'move not a JSON object' => ['POST '.$move, '[1]', null];
        yield 'move not JSON' => ['POST '.$move, '{"index":', null];
        yield 'move no expectedVersion' => ['POST '.$move, null, ['index' => 1]];
        yield 'move expectedVersion a string' => ['POST '.$move, null, ['expectedVersion' => '1', 'index' => 1]];
        yield 'move no index' => ['POST '.$move, null, ['expectedVersion' => 1]];
        yield 'move index a string' => ['POST '.$move, null, ['expectedVersion' => 1, 'index' => '1']];
        yield 'move index a float' => ['POST '.$move, null, ['expectedVersion' => 1, 'index' => 1.5]];
        yield 'move index null' => ['POST '.$move, null, ['expectedVersion' => 1, 'index' => null]];

        yield 'DELETE without expectedVersion' => ['DELETE /mine/sheet/fields/pow', null, null];
        yield 'DELETE expectedVersion not a number' => ['DELETE /mine/sheet/fields/pow?expectedVersion=abc', null, null];
        yield 'DELETE expectedVersion a float' => ['DELETE /mine/sheet/fields/pow?expectedVersion=1.5', null, null];
        yield 'DELETE expectedVersion empty' => ['DELETE /mine/sheet/fields/pow?expectedVersion=', null, null];
        $layout = '/mine/sheet/layout';
        $group = ['name' => 'Stats'];
        yield 'layout not a JSON object' => ['PUT '.$layout, '[1]', null];
        yield 'layout not JSON' => ['PUT '.$layout, '{"groups":', null];
        yield 'layout no expectedVersion' => ['PUT '.$layout, null, ['groups' => [$group]]];
        yield 'layout expectedVersion a string' => ['PUT '.$layout, null, ['expectedVersion' => '1', 'groups' => [$group]]];
        yield 'layout expectedVersion a float' => ['PUT '.$layout, null, ['expectedVersion' => 1.5, 'groups' => [$group]]];
        yield 'layout no groups' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'columns' => 1]];
        yield 'layout groups null' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => null]];
        yield 'layout groups a string' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => 'Stats']];
        yield 'layout groups an object' => ['PUT '.$layout, '{"expectedVersion":1,"groups":{"a":{"name":"Stats"}}}', null];
        yield 'layout groups an empty object' => ['PUT '.$layout, '{"expectedVersion":1,"groups":{}}', null];
        yield 'layout a group a string' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => ['Stats']]];
        yield 'layout a group a list' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => [[$group]]]];
        yield 'layout a group an empty list' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => [[]]]];
        yield 'layout a group null' => ['PUT '.$layout, null, ['expectedVersion' => 1, 'groups' => [$group, null]]];

        $declare = '/mine/sheet/layout/declare';
        yield 'declare not a JSON object' => ['POST '.$declare, '[1]', null];
        yield 'declare not JSON' => ['POST '.$declare, '{"expectedVersion":', null];
        yield 'declare no expectedVersion' => ['POST '.$declare, null, ['version' => 1]];
        yield 'declare expectedVersion a string' => ['POST '.$declare, null, ['expectedVersion' => '1']];
        yield 'declare expectedVersion a float' => ['POST '.$declare, null, ['expectedVersion' => 1.5]];

        $budgetPath = '/mine/sheet/point-budget';
        yield 'budget not a JSON object' => ['PUT '.$budgetPath, '[1]', null];
        yield 'budget not JSON' => ['PUT '.$budgetPath, '{"total":', null];
        yield 'budget no expectedVersion' => ['PUT '.$budgetPath, null, ['total' => 100, 'fields' => ['pow']]];
        yield 'budget expectedVersion a string' => ['PUT '.$budgetPath, null, ['expectedVersion' => '1', 'total' => 100, 'fields' => ['pow']]];
        yield 'budget expectedVersion a float' => ['PUT '.$budgetPath, null, ['expectedVersion' => 1.5, 'total' => 100, 'fields' => ['pow']]];
        yield 'DELETE budget without expectedVersion' => ['DELETE '.$budgetPath, null, null];
        yield 'DELETE budget expectedVersion not a number' => ['DELETE '.$budgetPath.'?expectedVersion=abc', null, null];
        yield 'DELETE budget expectedVersion an array' => ['DELETE '.$budgetPath.'?expectedVersion[]=1', null, null];

        yield 'DELETE layout without expectedVersion' => ['DELETE '.$layout, null, null];
        yield 'DELETE layout expectedVersion not a number' => ['DELETE '.$layout.'?expectedVersion=abc', null, null];
        yield 'DELETE layout expectedVersion an array' => ['DELETE '.$layout.'?expectedVersion[]=1', null, null];
        yield 'DELETE expectedVersion an array' => ['DELETE /mine/sheet/fields/pow?expectedVersion[]=1', null, null];
        yield 'DELETE expectedVersion a keyed array' => ['DELETE /mine/sheet/fields/pow?expectedVersion[a]=1', null, null];
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
        $before = $this->sheet();

        if (null === $raw) {
            $this->client->jsonRequest($method, self::BASE.$path, $json ?? []);
        } else {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);
        }

        self::assertResponseStatusCodeSame(400);
        self::assertNotSame('', $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupportedMediaType(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach ([['POST', '/mine/sheet/fields'], ['PUT', '/mine/sheet/fields/pow'], ['POST', '/mine/sheet/fields/pow/move'], ['PUT', '/mine/sheet/layout'], ['POST', '/mine/sheet/layout/declare'], ['PUT', '/mine/sheet/point-budget']] as [$method, $path]) {
            $this->client->request($method, self::BASE.$path, server: ['CONTENT_TYPE' => 'text/plain'], content: 'field');

            self::assertResponseStatusCodeSame(415, $method.' '.$path);
            self::assertNotSame('', $this->error());
        }
        self::assertSame(1, $this->sheet()['version']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingFields(): iterable
    {
        yield 'PUT an unknown field' => ['PUT /mine/sheet/fields/nothing'];
        yield 'DELETE an unknown field' => ['DELETE /mine/sheet/fields/nothing?expectedVersion=1'];
        yield 'move an unknown field' => ['POST /mine/sheet/fields/nothing/move'];
    }

    #[Test]
    #[DataProvider('missingFields')]
    public function anUnknownFieldIsNotFoundAndChangesNothing(string $request): void
    {
        [$method, $path] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest($method, self::BASE.$path, ['expectedVersion' => 1, 'field' => $this->luck(), 'index' => 0]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aStaleExpectedVersionIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]);
        $before = $this->sheet();

        foreach ([
            ['POST', '/mine/sheet/fields', ['expectedVersion' => 1, 'field' => ['key' => 'mana', 'label' => 'Mana', 'type' => 'number']]],
            ['PUT', '/mine/sheet/fields/luck', ['expectedVersion' => 1, 'field' => [...$this->luck(), 'label' => 'Changed']]],
            ['DELETE', '/mine/sheet/fields/luck?expectedVersion=1', []],
            ['POST', '/mine/sheet/fields/luck/move', ['expectedVersion' => 1, 'index' => 0]],
            ['PUT', '/mine/sheet/layout', ['expectedVersion' => 1, 'groups' => [['name' => 'General']]]],
            ['POST', '/mine/sheet/layout/declare', ['expectedVersion' => 1]],
            ['DELETE', '/mine/sheet/layout?expectedVersion=1', []],
            ['PUT', '/mine/sheet/point-budget', ['expectedVersion' => 1, 'total' => 100, 'fields' => ['pow']]],
            ['DELETE', '/mine/sheet/point-budget?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertNotSame('', $this->error());
        }
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aKeyAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields', ['expectedVersion' => 1, 'field' => [...$this->luck(), 'key' => 'hp']]);
        self::assertResponseStatusCodeSame(409);
        self::assertNotSame('', $this->error());

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/bonus', ['expectedVersion' => 1, 'field' => [...$this->luck(), 'key' => 'hp']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->sheet());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'POST a key that is not a slug' => ['POST /mine/sheet/fields', ['key' => 'Not A Key', 'label' => 'Luck', 'type' => 'number'], 'sheet.fields[4].key'];
        yield 'POST an unknown type' => ['POST /mine/sheet/fields', ['key' => 'luck', 'label' => 'Luck', 'type' => 'dial'], 'sheet.fields[4]'];
        yield 'POST an unknown property' => ['POST /mine/sheet/fields', ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'colour' => 'red'], 'sheet.fields[4]'];
        yield 'POST a number with min above max' => ['POST /mine/sheet/fields', ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 5, 'max' => 1], 'sheet.fields[4]'];
        yield 'POST a derived field reading itself' => ['POST /mine/sheet/fields', ['key' => 'luck', 'label' => 'Luck', 'type' => 'derived', 'formula' => '{luck}'], 'sheet.fields[4]'];
        yield 'PUT a field turned into a text a sheet effect sets' => ['PUT /mine/sheet/fields/hp', ['key' => 'hp', 'label' => 'Hit points', 'type' => 'text'], 'sceneTypes[0].play[0].outcomes[0].effects[1].field'];
    }

    /**
     * @param array<string, mixed> $field
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidFields')]
    public function aFieldBreakingTheReleaseRulesIsUnprocessableWithItsPath(string $request, array $field, string $path): void
    {
        [$method, $uri] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest($method, self::BASE.$uri, ['expectedVersion' => 1, 'field' => $field]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($path, $this->error());
        self::assertSame($before, $this->sheet());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null}>
     */
    public static function fieldsInUse(): iterable
    {
        yield 'deleting a field a derived field reads' => ['DELETE /mine/sheet/fields/pow?expectedVersion=1', null];
        yield 'deleting a field a check band reads' => ['DELETE /mine/sheet/fields/sanity?expectedVersion=1', null];
        yield 'renaming a field a derived field reads' => ['PUT /mine/sheet/fields/pow', ['key' => 'power', 'label' => 'Power', 'type' => 'number', 'min' => 1, 'max' => 99, 'default' => 50]];
        yield 'renaming a field a check band reads' => ['PUT /mine/sheet/fields/sanity', ['key' => 'nerve', 'label' => 'Sanity', 'type' => 'track', 'max' => 99]];
    }

    /**
     * @param array<string, mixed>|null $field
     */
    #[Test]
    #[DataProvider('fieldsInUse')]
    public function aFieldTheDraftReadsCannotBeDeletedOrRenamedAndTheResponseListsWhere(string $request, ?array $field): void
    {
        [$method, $uri] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest($method, self::BASE.$uri, null === $field ? [] : ['expectedVersion' => 1, 'field' => $field]);

        self::assertResponseStatusCodeSame(422);
        $paths = $this->json()['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertNotSame([], $paths);
        foreach ($paths as $path) {
            self::assertIsString($path);
            self::assertStringContainsString($path, $this->error());
        }
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aMoveOutOfRangeIsUnprocessableOnIndex(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        foreach ([-1, 4, 99] as $index) {
            $this->client->jsonRequest('POST', self::BASE.'/mine/sheet/fields/pow/move', ['expectedVersion' => 1, 'index' => $index]);

            self::assertResponseStatusCodeSame(422, (string) $index);
            self::assertStringStartsWith('index: ', $this->error());
        }
        self::assertSame($before, $this->sheet());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, string, non-empty-string}>
     */
    public static function layoutRefusals(): iterable
    {
        $stats = ['name' => 'Stats', 'columns' => 2];
        $health = ['name' => 'Health'];
        $put = static fn (array $groups, mixed $columns = 2): array => ['expectedVersion' => 1, 'columns' => $columns, 'groups' => $groups];

        yield 'saving before a layout is declared' => ['PUT /mine/sheet/layout', $put([$stats]), 'plain', 'sheet.groups: no layout is declared'];
        yield 'saving no groups' => ['PUT /mine/sheet/layout', $put([]), 'laidOut', 'groups: must not be empty'];
        yield 'dropping a group that holds fields' => ['PUT /mine/sheet/layout', $put([$stats]), 'laidOut', 'groups: cannot drop the group "Health", it holds 2 fields'];
        yield 'a previous name nobody has' => ['PUT /mine/sheet/layout', $put([$stats, ['name' => 'Health', 'previousName' => 'Ghost']]), 'laidOut', 'groups[1].previousName: '];
        yield 'a previous name that is not a string' => ['PUT /mine/sheet/layout', $put([$stats, ['name' => 'Health', 'previousName' => 7]]), 'laidOut', 'groups[1].previousName: '];
        yield 'two groups for one current group' => ['PUT /mine/sheet/layout', $put([$stats, $health, ['name' => 'Body', 'previousName' => 'Health']]), 'laidOut', 'groups[2]: '];
        yield 'a group without a name' => ['PUT /mine/sheet/layout', $put([$stats, $health, ['columns' => 2]]), 'laidOut', 'groups[2].name: '];
        yield 'columns a string' => ['PUT /mine/sheet/layout', $put([$stats, $health], 'two'), 'laidOut', 'sheet.columns: '];
        yield 'columns above 2' => ['PUT /mine/sheet/layout', $put([$stats, $health], 3), 'laidOut', 'sheet.columns: '];
        yield 'a blank group name' => ['PUT /mine/sheet/layout', $put([$stats, $health, ['name' => ' ']]), 'laidOut', 'sheet.groups[2].name: '];
        yield 'group columns above 4' => ['PUT /mine/sheet/layout', $put([['name' => 'Stats', 'columns' => 5], $health]), 'laidOut', 'sheet.groups[0].columns: '];
        yield 'a span above the sheet columns' => ['PUT /mine/sheet/layout', $put([['name' => 'Stats', 'columns' => 2, 'span' => 2], $health], 1), 'laidOut', 'sheet.groups[0].span: '];
        yield 'fewer group columns than a field spans' => ['PUT /mine/sheet/layout', $put([['name' => 'Stats'], $health]), 'laidOut', 'sheet.fields[0].span: '];
        yield 'declaring when groups are declared' => ['POST /mine/sheet/layout/declare', ['expectedVersion' => 1], 'laidOut', 'sheet.groups: already declared'];
        yield 'declaring a sheet without fields' => ['POST /mine/sheet/layout/declare', ['expectedVersion' => 1], 'minimal', 'sheet.fields: add a field first'];
        yield 'removing when no layout is declared' => ['DELETE /mine/sheet/layout?expectedVersion=1', null, 'plain', 'sheet.groups: no declared layout'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param non-empty-string          $messageStart
     */
    #[Test]
    #[DataProvider('layoutRefusals')]
    public function aLayoutCommandTheDraftRefusesIsUnprocessableWithItsPathAndChangesNothing(string $request, ?array $body, string $state, string $messageStart): void
    {
        [$method, $uri] = explode(' ', $request);
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        match ($state) {
            'laidOut' => $this->draft('mine', edit: $this->laidOut(...)),
            'minimal' => $this->draft('mine', 'valid/v2-minimal'),
            default => $this->draft('mine'),
        };
        $before = $this->sheet();

        $this->client->jsonRequest($method, self::BASE.$uri, $body ?? []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($messageStart, $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aVersion1DraftRefusesEverySheetCommand(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');
        $before = $this->sheet('journal');

        foreach ([
            ['POST', '/journal/sheet/fields', ['expectedVersion' => 1, 'field' => $this->luck()]],
            ['PUT', '/journal/sheet/fields/luck', ['expectedVersion' => 1, 'field' => $this->luck()]],
            ['DELETE', '/journal/sheet/fields/luck?expectedVersion=1', []],
            ['POST', '/journal/sheet/fields/luck/move', ['expectedVersion' => 1, 'index' => 0]],
            ['PUT', '/journal/sheet/layout', ['expectedVersion' => 1, 'groups' => [['name' => 'Stats']]]],
            ['POST', '/journal/sheet/layout/declare', ['expectedVersion' => 1]],
            ['DELETE', '/journal/sheet/layout?expectedVersion=1', []],
            ['PUT', '/journal/sheet/point-budget', ['expectedVersion' => 1, 'total' => 100, 'fields' => ['pow']]],
            ['DELETE', '/journal/sheet/point-budget?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(422, $method);
            self::assertStringStartsWith('schemaVersion', $this->error());
        }
        self::assertSame($before, $this->sheet('journal'));
    }

    /**
     * Declares Stats (2 columns) with pow and bonus, and Health with hp and sanity, on a sheet of 2
     * columns; pow spans 2.
     *
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private function laidOut(array $release): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = self::at($release, 'sheet.fields');
        $fields = array_map(static fn (array $field): array => [...$field, 'group' => \in_array($field['key'], ['hp', 'sanity'], true) ? 'Health' : 'Stats', ...'pow' === $field['key'] ? ['span' => 2] : []], $fields);

        return ReleaseArrays::with($release, 'sheet', ['columns' => 2, 'groups' => [['name' => 'Stats', 'columns' => 2], ['name' => 'Health']], 'fields' => $fields]);
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private function withLuck(array $release): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = self::at($release, 'sheet.fields');

        return ReleaseArrays::with($release, 'sheet', ['fields' => [...$fields, $this->luck()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function luck(): array
    {
        return ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 0, 'max' => 10];
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
     * Publishes the fixture (edited by $edit) and copies it as the signed-in user's draft $key.
     *
     * @param (\Closure(array<mixed>): array<mixed>)|null $edit
     */
    private function draft(string $key, string $fixture = 'valid/v2-sheet-effects', ?\Closure $edit = null): void
    {
        $release = ReleaseArrays::fixture($fixture);
        if ($edit instanceof \Closure) {
            $release = $edit($release);
        }
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9%03d', ++$this->releases), $release, false));
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key, 'source' => ['key' => self::at($release, 'gameSystem.key')]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<mixed>
     */
    private function sheet(string $key = 'mine'): array
    {
        $this->client->request('GET', self::BASE.'/'.$key.'/sheet');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<mixed> $sheet a read of the sheet
     *
     * @return list<string> the keys of its fields, in order
     */
    private function keys(array $sheet): array
    {
        /** @var list<string> $keys */
        $keys = array_column((array) $sheet['fields'], 'key');

        return $keys;
    }

    /**
     * The value at a dotted path of nested arrays, or null.
     */
    private static function at(mixed $node, string $path): mixed
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
