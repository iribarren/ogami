<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignTransferController;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Continuing a campaign in a new one over the Play API (ADR 0046), on the release
 * "valid/v2-every-part" (GameSystem "every-part"; Flows "heist", the default, and "quick";
 * Trackers "alarm", "edge", "chaos" and "heat").
 */
#[CoversClass(CampaignTransferController::class)]
final class CampaignTransferApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with a string "name", a list of strings "characterIds" and, to play a Flow, a string "flowKey", such as {"name": "Making a Name", "flowKey": "quick", "characterIds": []}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9401', ReleaseViews::fixtureContent('valid/v2-every-part'));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
    }

    #[Test]
    public function aTransferCreatesANewCampaignOnTheChosenFlowCarryingTheListsAndTheChosenCharacters(): void
    {
        $source = $this->createCampaign('heist');
        $vi = $this->add($source, 'characters', ['name' => 'Vi']);
        $this->add($source, 'characters', ['name' => 'Jax']);
        $npc = $this->add($source, 'npcs', ['name' => 'Mira', 'agenda' => 'Wants the ledger']);
        $thread = $this->add($source, 'threads', ['name' => 'The vault']);
        $place = $this->add($source, 'places', ['name' => 'Saltmarsh', 'notes' => 'A fishing town']);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/heat', $source), ['value' => 3]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $source));
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => '  Making a Name  ', 'flowKey' => 'quick', 'characterIds' => [$vi]]);

        self::assertResponseStatusCodeSame(201);
        $new = $this->json();
        $newId = $new['id'] ?? null;
        self::assertIsString($newId);
        self::assertNotSame($source, $newId);
        self::assertSame('/api/campaigns/'.$newId, $this->client->getResponse()->headers->get('Location'));
        self::assertSame($new, $this->campaign($newId));
        self::assertSame(['Making a Name', 'quick', 1, null], [$new['name'], $new['flowKey'], $this->pinnedVersion($new), $new['currentSessionNumber']]);
        self::assertSame([], $new['sessions']);
        self::assertSame([$vi], array_column($this->items($new, 'characters'), 'id'));
        self::assertSame([$npc], array_column($this->items($new, 'npcs'), 'id'));
        self::assertSame([$thread], array_column($this->items($new, 'threads'), 'id'));
        self::assertSame([$place], array_column($this->items($new, 'places'), 'id'));
        self::assertSame(-5, $this->heat($new));
        $kept = $this->campaign($source);
        self::assertSame(['The job', 'heist', 1, 3], [$kept['name'], $kept['flowKey'], $kept['currentSessionNumber'], $this->heat($kept)]);
        self::assertCount(2, $this->items($kept, 'characters'));
    }

    #[Test]
    public function withoutAFlowKeyTheNewCampaignPlaysFreely(): void
    {
        $source = $this->createCampaign('heist');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => 'Making a Name', 'characterIds' => []]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['flowKey']);
    }

    #[Test]
    public function aPromotedNpcWhoseCharacterStaysBehindLosesThePromotion(): void
    {
        $source = $this->createCampaign(null);
        $npc = $this->add($source, 'npcs', ['name' => 'Mira']);
        $this->client->request('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $source, $npc));
        self::assertResponseStatusCodeSame(201);
        $characterId = $this->json()['id'] ?? null;
        self::assertIsString($characterId);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => 'Left behind', 'characterIds' => []]);
        $left = $this->json();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => 'Carried', 'characterIds' => [$characterId]]);
        $carried = $this->json();

        self::assertSame([[null, 'closed'], []], [[$this->items($left, 'npcs')[0]['promotedTo'], $this->items($left, 'npcs')[0]['status']], $this->items($left, 'characters')]);
        self::assertSame([$characterId, [$characterId]], [$this->items($carried, 'npcs')[0]['promotedTo'], array_column($this->items($carried, 'characters'), 'id')]);
    }

    #[Test]
    public function aSourceThatIsNotFoundOrBelongsToAnotherPlayerIsNotFound(): void
    {
        $source = $this->createCampaign(null);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', self::UNKNOWN_ID), $this->body());
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/campaigns/not-an-id/transfer', $this->body());
        self::assertResponseStatusCodeSame(404);

        $this->signIn('grace@example.com', ['SOLO_PLAYER']);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), $this->body());
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->myCampaigns());
    }

    #[Test]
    public function aSourceOnAnOlderReleaseIsRefusedUntilItIsUpgraded(): void
    {
        $source = $this->createCampaign('heist');
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9402', ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-every-part'), 'gameSystem.description', 'A revised release.'));

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), $this->body());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('The campaign plays release 1 and release 2 is the latest: upgrade the campaign first.', $this->json()['error'] ?? null);
        self::assertCount(1, $this->myCampaigns());
    }

    #[Test]
    public function aFlowTheReleaseDoesNotHaveIsRefused(): void
    {
        $source = $this->createCampaign(null);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => 'Making a Name', 'flowKey' => 'the-long-con', 'characterIds' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Flow "the-long-con" not found.', $this->json()['error'] ?? null);
        self::assertCount(1, $this->myCampaigns());
    }

    #[Test]
    public function aBlankOrTooLongNameIsRefused(): void
    {
        $source = $this->createCampaign(null);

        foreach (['   ', str_repeat('a', 101)] as $name) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => $name, 'characterIds' => []]);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertCount(1, $this->myCampaigns());
    }

    #[Test]
    public function anUnknownBlankOrRepeatedCharacterIdIsRefused(): void
    {
        $source = $this->createCampaign(null);
        $vi = $this->add($source, 'characters', ['name' => 'Vi']);

        foreach ([[self::UNKNOWN_ID], [' '], [$vi, $vi]] as $characterIds) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), ['name' => 'Making a Name', 'characterIds' => $characterIds]);
            self::assertResponseStatusCodeSame(422);
        }

        self::assertCount(1, $this->myCampaigns());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'no name' => [['characterIds' => []]];
        yield 'a name that is not a string' => [['name' => 12, 'characterIds' => []]];
        yield 'no characterIds' => [['name' => 'Making a Name']];
        yield 'characterIds that is not a list' => [['name' => 'Making a Name', 'characterIds' => 'c-1']];
        yield 'characterIds that is an object' => [['name' => 'Making a Name', 'characterIds' => ['a' => 'c-1']]];
        yield 'characterIds that is an empty object' => [['name' => 'Making a Name', 'characterIds' => new \stdClass()]];
        yield 'a characterId that is not a string' => [['name' => 'Making a Name', 'characterIds' => [3]]];
        yield 'a flowKey that is not a string' => [['name' => 'Making a Name', 'flowKey' => 4, 'characterIds' => []]];
    }

    /**
     * @param array<mixed> $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsRefusedAndCreatesNothing(array $body): void
    {
        $source = $this->createCampaign(null);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MALFORMED, $this->json()['error'] ?? null);
        self::assertCount(1, $this->myCampaigns());
    }

    #[Test]
    public function aBodyThatIsNotAJsonObjectIsRefused(): void
    {
        $source = $this->createCampaign(null);

        $this->client->request('POST', \sprintf('/api/campaigns/%s/transfer', $source), server: ['CONTENT_TYPE' => 'application/json'], content: '[1, 2]');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', \sprintf('/api/campaigns/%s/transfer', $source), server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('POST', \sprintf('/api/campaigns/%s/transfer', $source), server: ['CONTENT_TYPE' => 'text/plain'], content: 'name');
        self::assertResponseStatusCodeSame(415);

        self::assertCount(1, $this->myCampaigns());
    }

    #[Test]
    public function aPlayerNeedsASessionAndTheSoloPlayerRole(): void
    {
        $source = $this->createCampaign(null);
        $this->client->request('POST', '/api/auth/logout');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), $this->body());
        self::assertResponseStatusCodeSame(401);

        $this->signIn('mod@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/transfer', $source), $this->body());
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return ['name' => 'Making a Name', 'characterIds' => []];
    }

    private function createCampaign(?string $flowKey): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'every-part', 'flowKey' => $flowKey]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function add(string $campaignId, string $list, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/%s', $campaignId, $list), $body);
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
     * @param array<mixed> $campaign
     *
     * @return list<array<mixed>>
     */
    private function items(array $campaign, string $key): array
    {
        $items = $campaign[$key] ?? null;
        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));

        return array_map(static fn (mixed $item): array => \is_array($item) ? $item : throw new \LogicException('Not a JSON object.'), $items);
    }

    /**
     * @param array<mixed> $campaign
     */
    private function pinnedVersion(array $campaign): mixed
    {
        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);

        return $pinned['version'] ?? null;
    }

    /**
     * @param array<mixed> $campaign
     */
    private function heat(array $campaign): mixed
    {
        $heat = array_find($this->items($campaign, 'trackers'), static fn (array $tracker): bool => 'heat' === $tracker['key']);
        self::assertIsArray($heat);

        return $heat['value'] ?? null;
    }

    /**
     * @return list<mixed>
     */
    private function myCampaigns(): array
    {
        $this->client->request('GET', '/api/campaigns');
        self::assertResponseIsSuccessful();

        return array_values($this->json());
    }

    /**
     * @param array<mixed> $content
     */
    private function publish(string $releaseId, array $content): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease($releaseId, $content, false));
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
