<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CharacterResponse;
use App\Play\Infrastructure\Http\ConditionOptionResponse;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
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
 * Conditions fields over the Play API (ADR 0041), on the release "valid/v2-sheet" without its Scene
 * Types, with the conditions field "wounds" (hurt, shaken, tired) and the derived field "strain"
 * (the count of marked wounds).
 */
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(CharacterResponse::class)]
#[CoversClass(SheetFieldResponse::class)]
#[CoversClass(ConditionOptionResponse::class)]
final class CampaignCharactersConditionsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'conditions');
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.9', ['key' => 'wounds', 'label' => 'Wounds', 'type' => 'conditions', 'hint' => 'Each costs a die.', 'group' => 'Health', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'shaken', 'label' => 'Shaken'], ['key' => 'tired', 'label' => 'Tired']]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.10', ['key' => 'strain', 'label' => 'Strain', 'type' => 'derived', 'formula' => '{wounds}']);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9104', $content, false));
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theSheetShowsTheOptionsOfAConditionsFieldInDeclaredOrder(): void
    {
        $campaign = $this->campaign($this->createCampaign());

        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column((array) ($pinned['sheet'] ?? null), null, 'key');
        self::assertSame(
            ['key' => 'wounds', 'label' => 'Wounds', 'hint' => 'Each costs a die.', 'group' => 'Health', 'type' => 'conditions', 'min' => null, 'max' => null, 'default' => null, 'multiline' => null, 'maxLength' => null, 'itemLabel' => null, 'maxItems' => null, 'formula' => null, 'roll' => null, 'startMax' => null, 'span' => 1, 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'shaken', 'label' => 'Shaken'], ['key' => 'tired', 'label' => 'Tired']], 'capacity' => null, 'maxStacks' => null, 'categories' => null],
            $fields['wounds'] ?? null,
        );
        self::assertArrayHasKey('options', $fields['reflexes']);
        self::assertNull($fields['reflexes']['options']);
    }

    #[Test]
    public function aNewCharacterStartsWithNothingMarkedAndAFormulaCountsTheMarks(): void
    {
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([], self::at($this->json(), 'values', 'wounds'));
        self::assertStringContainsString('"wounds":[]', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, self::at($this->json(), 'derived', 'strain'));
    }

    #[Test]
    public function theMarkedOptionsAreSavedInDeclaredOrderAndCounted(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'values' => ['wounds' => ['tired', 'hurt']]]);
        self::assertSame(['hurt', 'tired'], self::at($this->characters($id)[0], 'values', 'wounds'));
        self::assertSame(2, self::at($this->characters($id)[0], 'derived', 'strain'));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['wounds' => ['shaken']]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['shaken'], self::at($this->json(), 'values', 'wounds'));
        self::assertSame(1, self::at($this->json(), 'derived', 'strain'));
        self::assertSame(['shaken'], self::at($this->characters($id)[0], 'values', 'wounds'));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['wounds' => []]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::at($this->characters($id)[0], 'values', 'wounds'));
    }

    /**
     * @return iterable<string, array{mixed, string}> the value of "wounds" and the error
     */
    public static function invalidValues(): iterable
    {
        yield 'an unknown option' => [['ghost'], 'Field "wounds" has no option "ghost".'];
        yield 'a duplicate' => [['hurt', 'hurt'], 'Field "wounds" marks an option twice.'];
        yield 'a string' => ['hurt', 'Field "wounds" must be a list of option keys.'];
        yield 'a number' => [2, 'Field "wounds" must be a list of option keys.'];
        yield 'an object' => [['hurt' => true], 'Field "wounds" must be a list of option keys.'];
        yield 'null' => [null, 'Field "wounds" must be a list of option keys.'];
        yield 'a number in the list' => [[1], 'Field "wounds" has no option "int".'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatBreaksTheFieldIsA422AndNothingIsSaved(mixed $value, string $error): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'values' => ['wounds' => ['hurt']]]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Mira', 'values' => ['wounds' => $value]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Violet', 'values' => ['wounds' => $value]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        self::assertSame([['Vi', ['hurt']]], array_map(static fn (array $character): array => [$character['name'], self::at($character, 'values', 'wounds')], $this->characters($id)));
    }

    #[Test]
    public function aConditionsValueSavedOnACampaignChangedMeanwhileIsAConflict(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Brann', 'values' => ['wounds' => ['hurt']]]],
            ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['wounds' => ['hurt']]]],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$characterId, []]], array_map(static fn (array $character): array => [$character['id'], self::at($character, 'values', 'wounds')], $this->characters($id)));
    }

    #[Test]
    public function aCharacterStoredBeforeTheFieldExistedReadsAsNothingMarked(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);
        $legacy = ['id' => $characterId, 'name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => ['reflexes' => 5], 'promotedFrom' => null];
        $this->connection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode([$legacy], \JSON_THROW_ON_ERROR), $id]);

        $character = $this->characters($id)[0];

        self::assertSame(['reflexes' => 5], $character['values'] ?? null);
        self::assertSame(0, self::at($character, 'derived', 'strain'), 'A formula reads the missing field as nothing marked.');

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['reflexes' => 5, 'wounds' => ['hurt']]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['hurt'], self::at($this->json(), 'values', 'wounds'));
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
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'conditions']);
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
