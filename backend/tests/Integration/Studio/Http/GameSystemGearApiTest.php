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
use App\Studio\Infrastructure\Http\SheetConflictResponse;
use App\Studio\Infrastructure\Http\SheetCurrencyResponse;
use App\Studio\Infrastructure\Http\SheetGearEntryResponse;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The gear catalog and the sheet currency of a GameSystem draft over the Studio API (ADR 0045): the
 * sheet read that carries them, the put and delete of a gear entry by key, the put and delete of the
 * currency, and their refusals. Runs against examples/cpr-campaign-in-acts (eight entries, the
 * currency "eddies" in "eb", the inventories "gear" and "cyberware" and the shop sceneTypes[20].play[0]
 * that offers whole categories) and valid/v2-sheet-effects (no gear, no currency). Owner only.
 */
#[CoversClass(GameSystemSheetController::class)]
#[CoversClass(GameSystemSheetResponse::class)]
#[CoversClass(SheetGearEntryResponse::class)]
#[CoversClass(SheetCurrencyResponse::class)]
#[CoversClass(SheetConflictResponse::class)]
#[CoversClass(OracleInUseResponse::class)]
final class GameSystemGearApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const string MARKET = 'examples/cpr-campaign-in-acts';
    private const array ROUTES = [
        'saveGearEntry' => ['PUT', '/mine/gear/rope'],
        'removeGearEntry' => ['DELETE', '/mine/gear/rope?expectedVersion=1'],
        'saveCurrency' => ['PUT', '/mine/sheet/currency'],
        'removeCurrency' => ['DELETE', '/mine/sheet/currency?expectedVersion=1'],
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
        $this->draft('mine', self::MARKET);
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['mine', 'nothing'] as $key) {
            $this->client->jsonRequest($method, str_replace('/mine/', '/'.$key.'/', $path), ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 1, 'field' => 'eddies']);

            self::assertResponseStatusCodeSame(404, $method.' '.$key);
            self::assertSame(\sprintf('No GameSystem "%s".', $key), $this->error());
        }

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        self::assertSame(1, $this->sheet()['version'], 'Nothing changed.');
    }

    #[Test]
    public function theCatalogAndTheCurrencyAreReadWithTheSheet(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);

        $sheet = $this->sheet();

        self::assertCount(8, (array) $sheet['gear']);
        self::assertSame(['key' => 'used-pistol', 'name' => 'Used pistol', 'cost' => 50, 'slots' => 1, 'category' => 'Gear', 'description' => null], self::at($sheet, 'gear.0'));
        self::assertSame(['key' => 'armor-jacket', 'name' => 'Armor jacket', 'cost' => 100, 'slots' => 2, 'category' => 'Gear', 'description' => 'Light armor that passes for street wear.'], self::at($sheet, 'gear.1'));
        self::assertSame(['field' => 'eddies', 'unit' => 'eb'], $sheet['currency']);
    }

    #[Test]
    public function aDraftStoredBeforeGearAndCurrencyReadsNoneOfThem(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->draft('old', 'valid/minimal');

        foreach (['mine', 'old'] as $key) {
            $sheet = $this->sheet($key);
            self::assertSame([], $sheet['gear']);
            self::assertNull($sheet['currency']);
        }
    }

    #[Test]
    public function anEntryIsPutAppendedThenReplacedInPlaceAndTheLastDeleteEmptiesTheCatalog(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/rope', ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 5, 'category' => 'Tools', 'description' => 'Fifty feet.', 'slots' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
        self::assertSame([['key' => 'rope', 'name' => 'Rope', 'cost' => 5, 'slots' => 1, 'category' => 'Tools', 'description' => 'Fifty feet.']], $this->json()['gear']);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/torch', ['expectedVersion' => 2, 'name' => 'Torch', 'cost' => 1, 'slots' => 0]);
        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/rope', ['expectedVersion' => 3, 'name' => 'Hemp rope', 'cost' => 6]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['rope', 'Hemp rope', 1, null], ['torch', 'Torch', 0, null]],
            array_map(static fn (mixed $entry): array => \is_array($entry) ? [$entry['key'], $entry['name'], $entry['slots'], $entry['category']] : [], (array) $this->json()['gear']),
        );

        $this->client->request('DELETE', self::BASE.'/mine/gear/rope?expectedVersion=4');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['torch'], array_column((array) $this->json()['gear'], 'key'));
        $this->client->request('DELETE', self::BASE.'/mine/gear/torch?expectedVersion=5');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['gear']);
        self::assertSame(6, $this->json()['version']);
    }

    #[Test]
    public function theCatalogAndTheCurrencySurviveAReloadOfTheWholeDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/rope', ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 5]);
        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/bonus', ['expectedVersion' => 2, 'field' => ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'number']]);
        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/currency', ['expectedVersion' => 3, 'field' => 'bonus', 'unit' => 'cr']);
        self::assertResponseStatusCodeSame(200);
        $written = $this->json();

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame($written, $this->sheet());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}> the body and where the 422 points
     */
    public static function invalidEntries(): iterable
    {
        yield 'a key in the body' => [['name' => 'Rope', 'cost' => 5, 'key' => 'other'], 'gear[1].key'];
        yield 'a name taken, ignoring case' => [['name' => ' used PISTOL ', 'cost' => 5], 'gear[8].name'];
        yield 'no name' => [['cost' => 5], 'gear[8].name'];
        yield 'a negative cost' => [['name' => 'Rope', 'cost' => -1], 'gear[8].cost'];
        yield 'a fractional cost' => [['name' => 'Rope', 'cost' => 1.5], 'gear[8].cost'];
        yield 'too many slots' => [['name' => 'Rope', 'cost' => 1, 'slots' => 101], 'gear[8].slots'];
        yield 'a blank category' => [['name' => 'Rope', 'cost' => 1, 'category' => ''], 'gear[8].category'];
        yield 'an unknown property' => [['name' => 'Rope', 'cost' => 1, 'weight' => 3], 'gear[8]'];
    }

    /**
     * @param array<string, mixed> $body
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidEntries')]
    public function anEntryThatBreaksTheReleaseContractIsRefusedWithItsPath(array $body, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);
        $before = $this->sheet();

        // The "gear[1].key" case replaces the second entry, so it needs an existing key.
        $key = 'gear[1].key' === $path ? 'armor-jacket' : 'rope';
        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/'.$key, ['expectedVersion' => 1, ...$body]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($path, $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aCategoryAnInventoryAcceptsCannotLoseItsLastEntry(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);

        $this->client->request('DELETE', self::BASE.'/mine/gear/cyberoptic?expectedVersion=1');
        self::assertResponseStatusCodeSame(200);
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/gear/neural-link?expectedVersion=2');
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('sheet.fields[2].categories[0]', $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function anEntryAShopOffersByKeyCannotBeDeletedAndTheBodyListsWhere(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET, static fn (array $release): array => ReleaseArrays::with($release, 'sceneTypes.20.play.0.offer', ['gear' => ['used-pistol'], 'categories' => ['Food', 'Chrome']]));
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/gear/used-pistol?expectedVersion=1');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sceneTypes[20].play[0].offer.gear[0]'], $this->json()['paths']);
        self::assertStringStartsWith('The gear "used-pistol" is used at', $this->error());
        self::assertSame($before, $this->sheet());

        $this->client->request('DELETE', self::BASE.'/mine/gear/armor-jacket?expectedVersion=1');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function deletingAnUnknownEntryIsNotFoundAndChangesNothing(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/gear/rope?expectedVersion=1');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('rope', $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function theCurrencyIsPutReplacedAndRemoved(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/currency', ['expectedVersion' => 1, 'field' => 'pow', 'unit' => 'gp']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['field' => 'pow', 'unit' => 'gp'], $this->json()['currency']);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/currency', ['expectedVersion' => 2, 'field' => 'pow', 'unit' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['field' => 'pow', 'unit' => null], $this->json()['currency']);

        $this->client->request('DELETE', self::BASE.'/mine/sheet/currency?expectedVersion=3');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['currency']);
        self::assertSame(4, $this->json()['version']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}> the body and where the 422 points
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'an unknown field' => [['field' => 'nothing'], 'sheet.currency.field'];
        yield 'a derived field' => [['field' => 'bonus'], 'sheet.currency.field'];
        yield 'a track' => [['field' => 'hp'], 'sheet.currency.field'];
        yield 'no field' => [['unit' => 'gp'], 'sheet.currency'];
        yield 'a unit too long' => [['field' => 'pow', 'unit' => 'far too long'], 'sheet.currency.unit'];
        yield 'an unknown property' => [['field' => 'pow', 'color' => 'gold'], 'sheet.currency'];
    }

    /**
     * @param array<string, mixed> $body
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidCurrencies')]
    public function aCurrencyThatBreaksTheReleaseContractIsRefusedWithItsPath(array $body, string $path): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->sheet();

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/currency', ['expectedVersion' => 1, ...$body]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($path, $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function removingAnAbsentCurrencyIsNotFoundAndAShopKeepsTheCurrency(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->draft('market', self::MARKET);

        $this->client->request('DELETE', self::BASE.'/mine/sheet/currency?expectedVersion=1');
        self::assertResponseStatusCodeSame(404);

        $before = $this->sheet('market');
        $this->client->request('DELETE', self::BASE.'/market/sheet/currency?expectedVersion=1');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('sheet.currency', $this->error());
        self::assertSame($before, $this->sheet('market'));
    }

    #[Test]
    public function theCurrencyFieldCannotBeDeletedRenamedOrRetyped(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);
        $before = $this->sheet();

        $this->client->request('DELETE', self::BASE.'/mine/sheet/fields/eddies?expectedVersion=1');
        self::assertResponseStatusCodeSame(422);
        self::assertContains('sheet.currency.field', (array) $this->json()['paths']);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/eddies', ['expectedVersion' => 1, 'field' => ['key' => 'gold', 'label' => 'Gold', 'type' => 'number']]);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('sheet.currency.field', (array) $this->json()['paths']);

        $this->client->jsonRequest('PUT', self::BASE.'/mine/sheet/fields/eddies', ['expectedVersion' => 1, 'field' => ['key' => 'eddies', 'label' => 'Eddies', 'type' => 'text']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('sheet.currency.field', $this->error());
        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aMalformedBodyIsABadRequestAndANonJsonOneIsUnsupported(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        foreach (['/mine/gear/rope', '/mine/sheet/currency'] as $path) {
            foreach ([['name' => 'Rope', 'cost' => 1, 'field' => 'pow'], ['expectedVersion' => '1', 'name' => 'Rope', 'cost' => 1, 'field' => 'pow']] as $body) {
                $this->client->jsonRequest('PUT', self::BASE.$path, $body);
                self::assertResponseStatusCodeSame(400, $path);
                self::assertStringContainsString('"expectedVersion"', $this->error());
            }

            $this->client->request('PUT', self::BASE.$path, server: ['CONTENT_TYPE' => 'text/plain'], content: 'Rope');
            self::assertResponseStatusCodeSame(415, $path);

            $this->client->request('PUT', self::BASE.$path, server: ['CONTENT_TYPE' => 'application/json'], content: '[1, 2');
            self::assertResponseStatusCodeSame(400, $path);
        }

        foreach (['/mine/gear/rope', '/mine/sheet/currency'] as $path) {
            foreach (['', '?expectedVersion=', '?expectedVersion=one', '?expectedVersion[]=1'] as $query) {
                $this->client->request('DELETE', self::BASE.$path.$query);
                self::assertResponseStatusCodeSame(400, $path.$query);
            }
        }

        self::assertSame(1, $this->sheet()['version']);
    }

    #[Test]
    public function aConcurrentWriteIsAConflictWithItsCode(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine', self::MARKET);
        $this->client->jsonRequest('PUT', self::BASE.'/mine/gear/rope', ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 5, 'category' => 'Gear']);
        $before = $this->sheet();

        foreach ([
            ['PUT', '/mine/gear/rope', ['expectedVersion' => 1, 'name' => 'Stale', 'cost' => 1]],
            ['DELETE', '/mine/gear/rope?expectedVersion=1', []],
            ['PUT', '/mine/sheet/currency', ['expectedVersion' => 1, 'field' => 'eddies']],
            ['DELETE', '/mine/sheet/currency?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertSame('modified_concurrently', $this->json()['code']);
        }

        self::assertSame($before, $this->sheet());
    }

    #[Test]
    public function aLibraryPackRefusesTheGearAndCurrencyCommands(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', '/api/studio/library/packs', ['key' => 'names', 'name' => 'Names']);
        self::assertResponseStatusCodeSame(201);

        foreach ([
            ['PUT', '/names/gear/rope', ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 1]],
            ['DELETE', '/names/gear/rope?expectedVersion=1', []],
            ['PUT', '/names/sheet/currency', ['expectedVersion' => 1, 'field' => 'pow']],
            ['DELETE', '/names/sheet/currency?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(409, $method.' '.$path);
            self::assertSame('library_pack', $this->json()['code']);
        }
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesTheGearAndCurrencyCommands(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('old', 'valid/minimal');

        foreach ([
            ['PUT', '/old/gear/rope', ['expectedVersion' => 1, 'name' => 'Rope', 'cost' => 1]],
            ['DELETE', '/old/gear/rope?expectedVersion=1', []],
            ['PUT', '/old/sheet/currency', ['expectedVersion' => 1, 'field' => 'pow']],
            ['DELETE', '/old/sheet/currency?expectedVersion=1', []],
        ] as [$method, $path, $body]) {
            $this->client->jsonRequest($method, self::BASE.$path, $body);

            self::assertResponseStatusCodeSame(422, $method.' '.$path);
            self::assertStringStartsWith('schemaVersion', $this->error());
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
