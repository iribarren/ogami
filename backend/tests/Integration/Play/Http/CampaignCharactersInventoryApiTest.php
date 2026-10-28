<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CharacterResponse;
use App\Play\Infrastructure\Http\CurrencyResponse;
use App\Play\Infrastructure\Http\GearEntryResponse;
use App\Play\Infrastructure\Http\PinnedReleaseResponse;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Unit\Play\Infrastructure\GameSystem\GameSystemReleaseTranslatorGearTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gear, the currency and inventory fields over the Play API (ADR 0045), on the release
 * "examples/cpr-campaign-in-acts" without its Night Market: the currency "eddies", the inventory
 * "gear" (20 slots, Gear and Food) and the inventory "cyberware" (10 stacks, Chrome).
 */
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(CharacterResponse::class)]
#[CoversClass(SheetFieldResponse::class)]
#[CoversClass(PinnedReleaseResponse::class)]
#[CoversClass(GearEntryResponse::class)]
#[CoversClass(CurrencyResponse::class)]
final class CampaignCharactersInventoryApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9105', GameSystemReleaseTranslatorGearTest::playable(), false));
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignShowsTheGearCatalogTheCurrencyAndTheInventoryFields(): void
    {
        $pinned = $this->campaign($this->createCampaign())['pinnedRelease'] ?? null;

        self::assertIsArray($pinned);
        self::assertSame(['field' => 'eddies', 'unit' => 'eb'], $pinned['currency'] ?? null);
        $gear = (array) ($pinned['gear'] ?? null);
        self::assertCount(8, $gear);
        self::assertSame(['key' => 'used-pistol', 'name' => 'Used pistol', 'cost' => 50, 'slots' => 1, 'category' => 'Gear', 'description' => null], $gear[0] ?? null);
        self::assertSame(['key' => 'armor-jacket', 'name' => 'Armor jacket', 'cost' => 100, 'slots' => 2, 'category' => 'Gear', 'description' => 'Light armor that passes for street wear.'], $gear[1] ?? null);
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column((array) ($pinned['sheet'] ?? null), null, 'key');
        self::assertSame(
            ['key' => 'gear', 'label' => 'Gear', 'hint' => null, 'group' => null, 'type' => 'inventory', 'min' => null, 'max' => null, 'default' => null, 'multiline' => null, 'maxLength' => null, 'itemLabel' => null, 'maxItems' => null, 'formula' => null, 'roll' => null, 'startMax' => null, 'span' => 1, 'options' => null, 'capacity' => 20, 'maxStacks' => 50, 'categories' => ['Gear', 'Food']],
            $fields['gear'] ?? null,
        );
        self::assertSame([null, 10, ['Chrome']], [$fields['cyberware']['capacity'], $fields['cyberware']['maxStacks'], $fields['cyberware']['categories']]);
        self::assertSame([null, null, null], [$fields['eddies']['capacity'], $fields['eddies']['maxStacks'], $fields['eddies']['categories']]);
    }

    #[Test]
    public function aNewCharacterStartsWithEmptyInventories(): void
    {
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"gear":[]', (string) $this->client->getResponse()->getContent());
        self::assertSame([[], []], [self::at($this->json(), 'values', 'gear'), self::at($this->json(), 'values', 'cyberware')]);
    }

    #[Test]
    public function stacksAreSavedAndReadBackInTheirCanonicalForm(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'values' => ['gear' => [['quantity' => 2, 'gear' => 'used-pistol'], ['slots' => 1, 'quantity' => 1, 'name' => ' Rope ']]]]);
        $stacks = [['gear' => 'used-pistol', 'quantity' => 2], ['name' => 'Rope', 'quantity' => 1, 'slots' => 1]];
        self::assertSame($stacks, self::at($this->characters($id)[0], 'values', 'gear'));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['gear' => [['gear' => 'kibble', 'quantity' => 3]], 'cyberware' => [['gear' => 'cyberoptic', 'quantity' => 1]]]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['gear' => 'kibble', 'quantity' => 3]], self::at($this->json(), 'values', 'gear'));
        self::assertSame([['gear' => 'cyberoptic', 'quantity' => 1]], self::at($this->characters($id)[0], 'values', 'cyberware'));
    }

    /**
     * @return iterable<string, array{mixed, string}> the value of "gear" and the error
     */
    public static function invalidValues(): iterable
    {
        yield 'a string' => ['used-pistol', 'Field "gear" must be a list of stacks.'];
        yield 'an unknown gear' => [[['gear' => 'katana', 'quantity' => 1]], 'Field "gear" does not accept gear "katana".'];
        yield 'gear of another category' => [[['gear' => 'cyberoptic', 'quantity' => 1]], 'Field "gear" does not accept gear "cyberoptic".'];
        yield 'the same gear twice' => [[['gear' => 'kibble', 'quantity' => 1], ['gear' => 'kibble', 'quantity' => 1]], 'Field "gear" holds gear "kibble" in two stacks.'];
        yield 'a quantity of zero' => [[['gear' => 'kibble', 'quantity' => 0]], 'The quantity of a stack of field "gear" must be an integer from 1 to 9999.'];
        yield 'an unknown key' => [[['gear' => 'kibble', 'quantity' => 1, 'note' => 'x']], 'A stack of field "gear" must have exactly gear, quantity.'];
        yield 'a free stack without slots' => [[['name' => 'Rope', 'quantity' => 1]], 'A stack of field "gear" must have exactly name, quantity, slots.'];
        yield 'a blank name' => [[['name' => ' ', 'quantity' => 1, 'slots' => 0]], 'The name of a stack of field "gear" must be 1 to 200 characters, got 0.'];
        yield 'over the capacity' => [[['gear' => 'armor-jacket', 'quantity' => 10], ['name' => 'Rope', 'quantity' => 1, 'slots' => 1]], 'Field "gear" holds 20 slots, 21 used.'];
        yield 'over the maximum stacks' => [array_map(static fn (int $n): array => ['name' => 'N'.$n, 'quantity' => 1, 'slots' => 0], range(1, 51)), 'Field "gear" holds at most 50 stacks, got 51.'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatBreaksTheFieldIsA422AndNothingIsSaved(mixed $value, string $error): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'values' => ['gear' => [['gear' => 'kibble', 'quantity' => 1]]]]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Mira', 'values' => ['gear' => $value]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Violet', 'values' => ['gear' => $value]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        self::assertSame([['Vi', [['gear' => 'kibble', 'quantity' => 1]]]], array_map(static fn (array $character): array => [$character['name'], self::at($character, 'values', 'gear')], $this->characters($id)));
    }

    #[Test]
    public function aValueWithNullInPlaceOfStacksIsA422(): void
    {
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Mira', 'values' => ['gear' => null]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Field "gear" must be a list of stacks.'], $this->json());
    }

    #[Test]
    public function anInventoryValueSavedOnACampaignChangedMeanwhileIsAConflict(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);
        $body = ['name' => 'Vi', 'values' => ['gear' => [['gear' => 'kibble', 'quantity' => 1]]]];

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Brann'] + $body],
            ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), $body],
        ] as [$method, $uri, $payload]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $payload);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$characterId, []]], array_map(static fn (array $character): array => [$character['id'], self::at($character, 'values', 'gear')], $this->characters($id)));
    }

    #[Test]
    public function aCharacterStoredBeforeInventoriesExistedReadsUnchangedAndCanFillThem(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);
        $legacy = ['id' => $characterId, 'name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => ['eddies' => 80], 'promotedFrom' => null];
        $this->connection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode([$legacy], \JSON_THROW_ON_ERROR), $id]);

        self::assertSame(['eddies' => 80], $this->characters($id)[0]['values'] ?? null);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['eddies' => 80, 'gear' => [['gear' => 'kibble', 'quantity' => 2]]]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['gear' => 'kibble', 'quantity' => 2]], self::at($this->json(), 'values', 'gear'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWhileAnotherRequestSaves(string $campaignId, string $method, string $uri, array $body): void
    {
        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $campaignId) {
            public function __construct(private Connection $connection, private string $campaignId)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement('UPDATE play_campaign SET version = version + 1 WHERE id = ?', [$this->campaignId]);
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->client->jsonRequest($method, $uri, $body);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }
    }

    /**
     * @param array<mixed> $character a Character as the API shows it
     */
    private static function at(array $character, string $section, string $key): mixed
    {
        return ((array) ($character[$section] ?? null))[$key] ?? null;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'cpr-campaign-in-acts']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addCharacter(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function campaign(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function characters(string $id): array
    {
        $characters = $this->campaign($id)['characters'] ?? null;
        self::assertIsArray($characters);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($characters);

        return $list;
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
