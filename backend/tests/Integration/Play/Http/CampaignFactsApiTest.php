<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\FactLinkResponse;
use App\Play\Infrastructure\Http\FactResponse;
use App\Play\Infrastructure\Http\FactSlotResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Campaign facts over the Play API, on the schema version 2 release "valid/v2-campaign-facts", whose
 * Fact slots are "city" (text), "prince" (npc), "goal" (thread) and "ambition" (text).
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(FactResponse::class)]
#[CoversClass(FactLinkResponse::class)]
#[CoversClass(FactSlotResponse::class)]
final class CampaignFactsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseViews::fixtureContent('valid/v2-campaign-facts'), false));
    }

    #[Test]
    public function aNewCampaignShowsTheFactSlotsOfItsReleaseAndNoFacts(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame([
            ['key' => 'city', 'label' => 'City', 'type' => 'text', 'hint' => 'Where the chronicle begins.'],
            ['key' => 'prince', 'label' => 'Prince', 'type' => 'npc', 'hint' => 'Who rules the city.'],
            ['key' => 'goal', 'label' => 'Goal', 'type' => 'thread', 'hint' => null],
            ['key' => 'ambition', 'label' => 'Ambition', 'type' => 'text', 'hint' => null],
        ], $campaign['factSlots'] ?? null);
        self::assertSame([], $campaign['facts'] ?? null);
    }

    #[Test]
    public function factsShowTheirValuesAndLinkNamesAndARenameShowsEverywhere(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $id), ['name' => 'Valen']);
        $npcId = $this->json()['id'] ?? null;
        self::assertIsString($npcId);
        $this->seed($id, static function ($campaign, \App\Play\Domain\GameSystem\GameSystemSnapshot $release) use ($npcId): void {
            $campaign->addFreeFact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001'), 'Guild', 'Owes a debt', [FactLink::npc(NpcId::fromString($npcId))], $release);
            $campaign->fillFactSlot(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002'), 'prince', FactFill::existingNpc(NpcId::fromString($npcId)), $release);
            $campaign->fillFactSlot(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003'), 'city', FactFill::text('Ashford'), $release);
            $campaign->editFact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003'), null, null, [FactLink::fact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001'))], $release);
        });

        $this->client->request('GET', '/api/campaigns/'.$id);
        $before = $this->json()['facts'] ?? null;
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId), ['name' => 'Valen Vane']);
        $this->client->request('GET', '/api/campaigns/'.$id);
        $after = $this->json()['facts'] ?? null;

        $npcLink = ['kind' => 'npc', 'id' => $npcId, 'name' => 'Valen'];
        self::assertSame([
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003', 'slot' => 'city', 'label' => 'City', 'text' => 'Ashford', 'subject' => null, 'value' => 'Ashford', 'links' => [['kind' => 'fact', 'id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', 'name' => 'Guild']]],
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002', 'slot' => 'prince', 'label' => 'Prince', 'text' => null, 'subject' => $npcLink, 'value' => 'Valen', 'links' => []],
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', 'slot' => null, 'label' => 'Guild', 'text' => 'Owes a debt', 'subject' => null, 'value' => 'Owes a debt', 'links' => [$npcLink]],
        ], $before);
        self::assertSame('Valen Vane', $after[1]['value'] ?? null);
        self::assertSame('Valen Vane', $after[1]['subject']['name'] ?? null);
        self::assertSame('Valen Vane', $after[2]['links'][0]['name'] ?? null);
    }

    /**
     * Seeds the campaign through the domain: slot filling has no endpoint yet.
     *
     * @param callable(\App\Play\Domain\Campaign\Campaign, \App\Play\Domain\GameSystem\GameSystemSnapshot): void $change
     */
    private function seed(string $campaignId, callable $change): void
    {
        $campaigns = self::getContainer()->get(CampaignRepository::class);
        $campaign = $campaigns->ofId(CampaignId::fromString($campaignId)) ?? throw new \LogicException('No campaign.');
        $pinned = $campaign->pinnedRelease();
        $change($campaign, self::getContainer()->get(PublishedGameSystemReleases::class)->get($pinned->gameSystemKey(), $pinned->releaseVersion()));
        $campaigns->save($campaign);
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'campaign-facts']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
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
