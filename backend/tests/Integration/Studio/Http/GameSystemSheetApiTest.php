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
use App\Studio\Infrastructure\Http\SheetTrackerResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sheet of a GameSystem draft over the Studio API: the read and the field commands. Runs against
 * valid/v2-sheet-effects: fields pow, hp, sanity and bonus (derived from pow); pow is read by bonus
 * and by sheet effects, sanity by a check band. Owner only: another user's draft is not found.
 */
#[CoversClass(GameSystemSheetController::class)]
#[CoversClass(GameSystemSheetResponse::class)]
#[CoversClass(SheetGroupResponse::class)]
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
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'field' => $this->luck(), 'index' => 0]);

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

        foreach ([['POST', '/mine/sheet/fields'], ['PUT', '/mine/sheet/fields/pow'], ['POST', '/mine/sheet/fields/pow/move']] as [$method, $path]) {
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
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(422, $method);
            self::assertStringStartsWith('schemaVersion', $this->error());
        }
        self::assertSame($before, $this->sheet('journal'));
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
