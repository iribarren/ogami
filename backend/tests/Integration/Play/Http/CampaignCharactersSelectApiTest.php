<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Select fields over the Play API (ADR 0048), on the release "valid/v2-sheet" without its Scene Types,
 * with the select fields "stance" (guarded by default, or reckless) and "origin" (no default).
 */
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(SheetFieldResponse::class)]
final class CampaignCharactersSelectApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'select');
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.9', ['key' => 'stance', 'label' => 'Stance', 'type' => 'select', 'options' => [['key' => 'guarded', 'label' => 'Guarded'], ['key' => 'reckless', 'label' => 'Reckless']], 'default' => 'guarded']);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.10', ['key' => 'origin', 'label' => 'Origin', 'type' => 'select', 'options' => [['key' => 'coast', 'label' => 'Coast']]]);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9105', $content, false));
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theSheetShowsTheOptionsAndTheDefaultOfASelectField(): void
    {
        $campaign = $this->campaign($this->createCampaign());

        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column((array) ($pinned['sheet'] ?? null), null, 'key');
        self::assertSame('select', $fields['stance']['type'] ?? null);
        self::assertSame([['key' => 'guarded', 'label' => 'Guarded'], ['key' => 'reckless', 'label' => 'Reckless']], $fields['stance']['options'] ?? null);
        self::assertSame('guarded', $fields['stance']['defaultOption'] ?? null);
        self::assertArrayHasKey('defaultOption', $fields['origin']);
        self::assertNull($fields['origin']['defaultOption']);
        self::assertNull($fields['reflexes']['defaultOption']);
    }

    #[Test]
    public function aNewCharacterStartsWithTheDefaultOrNothingChosenAndKeepsAChoiceOrNull(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id);
        self::assertSame('guarded', $this->value($this->characters($id)[0], 'stance'));
        self::assertArrayHasKey('origin', (array) ($this->characters($id)[0]['values'] ?? null));
        self::assertNull($this->value($this->characters($id)[0], 'origin'));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['stance' => 'reckless', 'origin' => 'coast']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('reckless', $this->value($this->characters($id)[0], 'stance'));
        self::assertSame('coast', $this->value($this->characters($id)[0], 'origin'));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['stance' => null, 'origin' => null]]);
        self::assertResponseStatusCodeSame(200);
        $values = (array) ($this->characters($id)[0]['values'] ?? null);
        self::assertArrayHasKey('stance', $values);
        self::assertNull($values['stance']);
        self::assertArrayHasKey('origin', $values);
        self::assertNull($values['origin']);
    }

    /**
     * @return iterable<string, array{mixed, string}> the value of "stance" and the error
     */
    public static function invalidValues(): iterable
    {
        yield 'an unknown option' => ['ghost', 'Field "stance" has no option "ghost".'];
        yield 'another case' => ['Guarded', 'Field "stance" has no option "Guarded".'];
        yield 'a number' => [2, 'Field "stance" must be an option key or null.'];
        yield 'a list' => [['guarded'], 'Field "stance" must be an option key or null.'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatIsNotAnOptionIsA422AndNothingIsSaved(mixed $value, string $error): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Violet', 'values' => ['stance' => $value]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, $this->json()['error'] ?? null);
        self::assertSame('Vi', $this->characters($id)[0]['name'] ?? null);
        self::assertSame('guarded', $this->value($this->characters($id)[0], 'stance'));
    }

    #[Test]
    public function aCharacterStoredBeforeTheFieldExistedHasNoValueAndTakesOneOnSave(): void
    {
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id);
        $legacy = ['id' => $characterId, 'name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => ['reflexes' => 5], 'promotedFrom' => null];
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode([$legacy], \JSON_THROW_ON_ERROR), $id]);

        self::assertSame(['reflexes' => 5], $this->characters($id)[0]['values'] ?? null, 'A missing value reads as none chosen.');

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['reflexes' => 5, 'stance' => 'reckless']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('reckless', $this->value($this->json(), 'stance'));
    }

    /**
     * @param array<mixed> $character a Character as the API shows it
     */
    private function value(array $character, string $key): mixed
    {
        return ((array) ($character['values'] ?? null))[$key] ?? null;
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'select']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function addCharacter(string $campaignId): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $campaignId), ['name' => 'Vi']);
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
