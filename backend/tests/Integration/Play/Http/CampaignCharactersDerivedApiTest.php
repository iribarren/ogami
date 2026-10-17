<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CharacterResponse;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * DerivedValues over the Play API (ADR 0023), on the release "valid/v2-sheet" without its Scene Types
 * and with the counter "chaos" (1 to 9, starting at 5) and the derived fields "guard" (reflexes * 2
 * + luck + chaos), "vigor" (hp as a percentage of its max) and "broken" (reflexes / debt, which
 * divides by zero while the debt is 0).
 */
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(CharacterResponse::class)]
#[CoversClass(SheetFieldResponse::class)]
final class CampaignCharactersDerivedApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'derived');
        $content = ReleaseArrays::with($content, 'trackers', [['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.9', ['key' => 'guard', 'label' => 'Guard', 'type' => 'derived', 'formula' => '{reflexes} * 2 + {luck} + {tracker:chaos}']);
        $content = ReleaseArrays::with($content, 'sheet.fields.10', ['key' => 'vigor', 'label' => 'Vigor', 'type' => 'derived', 'formula' => '{hp} * 100 / {hp.max}']);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet.fields.11', ['key' => 'broken', 'label' => 'Broken', 'type' => 'derived', 'formula' => '{reflexes} / {debt}']);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', $content, false));
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theSheetShowsTheFormulaOfADerivedField(): void
    {
        $campaign = $this->campaign($this->createCampaign());

        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column((array) ($pinned['sheet'] ?? null), null, 'key');
        self::assertSame(
            ['key' => 'guard', 'label' => 'Guard', 'hint' => null, 'group' => null, 'type' => 'derived', 'min' => null, 'max' => null, 'default' => null, 'multiline' => null, 'maxLength' => null, 'itemLabel' => null, 'maxItems' => null, 'formula' => '{reflexes} * 2 + {luck} + {tracker:chaos}', 'span' => 1],
            $fields['guard'] ?? null,
        );
        self::assertArrayHasKey('formula', $fields['reflexes']);
        self::assertNull($fields['reflexes']['formula']);
    }

    #[Test]
    public function aCharacterShowsItsDerivedValuesInSheetOrderAndNullWhereAFormulaFails(): void
    {
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'values' => ['reflexes' => 7, 'luck' => -2, 'hp' => ['current' => 10]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['guard' => 17, 'vigor' => 25, 'broken' => null], $this->json()['derived'] ?? null, '7 * 2 - 2 + 5, 10 of 40, and a debt of 0');
        self::assertStringContainsString('"derived":{"guard":17,"vigor":25,"broken":null}', (string) $this->client->getResponse()->getContent());
        self::assertArrayNotHasKey('guard', (array) ($this->json()['values'] ?? null), 'A derived field is not stored.');
        self::assertSame(['guard' => 17, 'vigor' => 25, 'broken' => null], $this->characters($id)[0]['derived'] ?? null);
    }

    #[Test]
    public function aDerivedValueFollowsTheCampaignsTrackerAndTheCharactersEdits(): void
    {
        $id = $this->createCampaign();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'values' => ['reflexes' => 7]]);
        $characterId = $this->json()['id'] ?? null;
        self::assertIsString($characterId);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/chaos', $id), ['value' => 8]);
        self::assertResponseIsSuccessful();
        self::assertSame(['guard' => 22, 'vigor' => 100, 'broken' => null], $this->characters($id)[0]['derived'] ?? null, '7 * 2 + 0 + 8');

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['reflexes' => 7, 'debt' => 7]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['guard' => 22, 'vigor' => 100, 'broken' => 1], $this->json()['derived'] ?? null);
    }

    #[Test]
    public function aValueForADerivedFieldIsA422AndNothingIsSaved(): void
    {
        $id = $this->createCampaign();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi']);
        $characterId = $this->json()['id'] ?? null;
        self::assertIsString($characterId);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Mira', 'values' => ['guard' => 9]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Field "guard" is derived; it holds no value.'], $this->json());

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Violet', 'values' => ['guard' => 9]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Field "guard" is derived; it holds no value.'], $this->json());
        self::assertSame(['Vi'], array_column($this->characters($id), 'name'));
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'derived']);
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
