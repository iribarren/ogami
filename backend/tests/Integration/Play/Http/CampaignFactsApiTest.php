<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\FactIdGenerator;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignFactsController;
use App\Play\Infrastructure\Http\FactLinkResponse;
use App\Play\Infrastructure\Http\FactResponse;
use App\Play\Infrastructure\Http\FactSlotResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Campaign facts over the Play API, on the schema version 2 release "valid/v2-campaign-facts", whose
 * Fact slots are "city" (text), "prince" (npc), "goal" (thread) and "ambition" (text).
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(CampaignFactsController::class)]
#[CoversClass(FactResponse::class)]
#[CoversClass(FactLinkResponse::class)]
#[CoversClass(FactSlotResponse::class)]
final class CampaignFactsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED_ADD = 'Send a JSON object with string "label" and "text" and, optionally, a list or null "links" of {"kind": "npc" | "thread" | "fact", "id": "<uuid>"}, such as {"label": "The guild", "text": "Owes a debt.", "links": [{"kind": "npc", "id": "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"}]}.';
    private const string MALFORMED_EDIT = 'Send a JSON object with a list "links" of {"kind": "npc" | "thread" | "fact", "id": "<uuid>"} and string or null "label" and "text", such as {"label": "The guild", "text": "Owes a debt.", "links": []}.';
    private const string MALFORMED_FILL = 'Send a JSON object with exactly one of a string "text", a "subject" (the UUID of an NPC or Thread) and a string "name" (a new NPC or Thread), such as {"text": "Ashford"}.';

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
        $this->seed($id, static function ($campaign, GameSystemSnapshot $release) use ($npcId): void {
            $campaign->addFreeFact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001'), 'Guild', 'Owes a debt', [FactLink::npc(NpcId::fromString($npcId))], $release);
            $campaign->fillFactSlot(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002'), 'prince', FactFill::existingNpc(NpcId::fromString($npcId)), $release);
            $campaign->fillFactSlot(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003'), 'city', FactFill::text('Ashford'), $release);
            $campaign->editFact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003'), null, null, [FactLink::fact(FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001'))], $release);
        });

        $before = $this->campaignFacts($id);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId), ['name' => 'Valen Vane']);
        $after = $this->campaignFacts($id);

        $npcLink = ['kind' => 'npc', 'id' => $npcId, 'name' => 'Valen'];
        self::assertSame([
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003', 'slot' => 'city', 'label' => 'City', 'text' => 'Ashford', 'subject' => null, 'value' => 'Ashford', 'links' => [['kind' => 'fact', 'id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', 'name' => 'Guild']]],
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002', 'slot' => 'prince', 'label' => 'Prince', 'text' => null, 'subject' => $npcLink, 'value' => 'Valen', 'links' => []],
            ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', 'slot' => null, 'label' => 'Guild', 'text' => 'Owes a debt', 'subject' => null, 'value' => 'Owes a debt', 'links' => [$npcLink]],
        ], $before);
        $subject = $after[1]['subject'];
        $links = $after[2]['links'];
        self::assertIsArray($subject);
        self::assertIsArray($links);
        self::assertIsArray($links[0]);
        self::assertSame(['Valen Vane', 'Valen Vane', 'Valen Vane'], [$after[1]['value'], $subject['name'], $links[0]['name']]);
    }

    #[Test]
    public function aFreeFactIsAddedWithTrimmedValuesAndItsLinks(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id);
        $first = $this->addFact($id, ['label' => 'Other', 'text' => 'Text']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), ['label' => '  Guild ', 'text' => ' Owes a debt ', 'links' => [['kind' => 'npc', 'id' => $npcId], ['kind' => 'fact', 'id' => $first]]]);

        self::assertResponseStatusCodeSame(201);
        $fact = $this->json();
        self::assertIsString($fact['id'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $fact['id']);
        self::assertSame([
            'slot' => null,
            'label' => 'Guild',
            'text' => 'Owes a debt',
            'subject' => null,
            'value' => 'Owes a debt',
            'links' => [['kind' => 'npc', 'id' => $npcId, 'name' => 'Mira'], ['kind' => 'fact', 'id' => $first, 'name' => 'Other']],
        ], array_diff_key($fact, ['id' => 1]));
        self::assertSame($fact, $this->campaignFacts($id)[1] ?? null);
    }

    #[Test]
    public function aFreeFactIsAddedWithoutLinksWhenTheyAreOmittedOrNull(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([['label' => 'A', 'text' => 'Text'], ['label' => 'B', 'text' => 'Text', 'links' => null]] as $body) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), $body);

            self::assertResponseStatusCodeSame(201);
            self::assertSame([], $this->json()['links'] ?? null);
        }
    }

    #[Test]
    public function aFreeFactEditReplacesItsLabelTextAndLinks(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id);
        $threadId = $this->addThread($id);
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt', 'links' => [['kind' => 'npc', 'id' => $npcId]]]);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), ['label' => ' Thieves guild ', 'text' => 'Owes two debts', 'links' => [['kind' => 'thread', 'id' => $threadId]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $factId, 'slot' => null, 'label' => 'Thieves guild', 'text' => 'Owes two debts', 'subject' => null, 'value' => 'Owes two debts', 'links' => [['kind' => 'thread', 'id' => $threadId, 'name' => 'Vault']]], $this->json());
        self::assertSame([$this->json()], $this->campaignFacts($id));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), ['label' => 'Thieves guild', 'text' => 'Owes two debts', 'links' => []]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['links'] ?? null);
    }

    #[Test]
    public function aSlotFactTakesNewLinksOverTheApi(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id);
        $slotFact = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7010';
        $this->seed($id, static function (Campaign $campaign, GameSystemSnapshot $release) use ($slotFact): void {
            $campaign->fillFactSlot(FactId::fromString($slotFact), 'city', FactFill::text('Ashford'), $release);
        });

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $slotFact), ['links' => [['kind' => 'npc', 'id' => $npcId]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $slotFact, 'slot' => 'city', 'label' => 'City', 'text' => 'Ashford', 'subject' => null, 'value' => 'Ashford', 'links' => [['kind' => 'npc', 'id' => $npcId, 'name' => 'Mira']]], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not an object' => ['[3]'];
        yield 'invalid JSON' => ['{"label":'];
        yield 'a numeric label' => ['{"label": 3, "text": "T", "links": []}'];
        yield 'a numeric text' => ['{"label": "L", "text": 4, "links": []}'];
        yield 'links as a string' => ['{"label": "L", "text": "T", "links": "npc"}'];
        yield 'links as an object' => ['{"label": "L", "text": "T", "links": {"kind": "npc"}}'];
        yield 'links as an empty object' => ['{"label": "L", "text": "T", "links": {}}'];
        yield 'a link that is a string' => ['{"label": "L", "text": "T", "links": ["x"]}'];
        yield 'a link without a kind' => ['{"label": "L", "text": "T", "links": [{"id": "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999"}]}'];
        yield 'an unknown link kind' => ['{"label": "L", "text": "T", "links": [{"kind": "scene", "id": "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999"}]}'];
        yield 'a link id that is not a UUID' => ['{"label": "L", "text": "T", "links": [{"kind": "npc", "id": "npc-1"}]}'];
        yield 'a numeric link id' => ['{"label": "L", "text": "T", "links": [{"kind": "npc", "id": 3}]}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndNothingChanges(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/facts', $id), self::MALFORMED_ADD], ['PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), self::MALFORMED_EDIT]] as [$method, $uri, $message]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => $message], $this->json());
        }

        self::assertCount(1, $this->campaignFacts($id));
    }

    #[Test]
    public function missingOrMistypedFieldsAreABadRequest(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);

        foreach ([[], ['label' => 'L'], ['text' => 'T']] as $body) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), $body);
            self::assertResponseStatusCodeSame(400);
        }

        // An edit replaces the links, so a missing list is refused rather than read as "clear them".
        foreach ([[], ['label' => 'L', 'text' => 'T'], ['label' => 'L', 'text' => 'T', 'links' => null], ['label' => 3, 'text' => 'T', 'links' => []], ['label' => 'L', 'text' => 4, 'links' => []]] as $body) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), $body);
            self::assertResponseStatusCodeSame(400);
        }

        self::assertSame(['Guild'], array_column($this->campaignFacts($id), 'label'));
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/facts', $id), 'Send the fact as JSON.'], ['PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), 'Send the fact as JSON.']] as [$method, $uri, $message]) {
            $this->client->request($method, $uri, ['label' => 'L', 'text' => 'T', 'links' => []]);

            self::assertResponseStatusCodeSame(415);
            self::assertSame(['error' => $message], $this->json());
        }

        self::assertCount(1, $this->campaignFacts($id));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidFacts(): iterable
    {
        yield 'a blank label' => [['label' => '  ', 'text' => 'T', 'links' => []], 'The label must not be blank.'];
        yield 'a too long label' => [['label' => str_repeat('a', 101), 'text' => 'T', 'links' => []], 'The label must be at most 100 characters, got 101.'];
        yield 'a blank text' => [['label' => 'L', 'text' => " \n", 'links' => []], 'The text must not be blank.'];
        yield 'a too long text' => [['label' => 'L', 'text' => str_repeat('a', 2001), 'links' => []], 'The text must be at most 2000 characters, got 2001.'];
        yield 'a repeated link' => [['label' => 'L', 'text' => 'T', 'links' => [['kind' => 'npc', 'id' => self::UNKNOWN_ID], ['kind' => 'npc', 'id' => self::UNKNOWN_ID]]], \sprintf('The npc "%s" is linked twice.', self::UNKNOWN_ID)];
        yield 'more than 20 links' => [['label' => 'L', 'text' => 'T', 'links' => array_map(static fn (int $i): array => ['kind' => 'fact', 'id' => \sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04d', $i)], range(1, 21))], 'At most 20 links are allowed, got 21.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidFacts')]
    public function anInvalidFactIsUnprocessableAndNothingChanges(array $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);
        $before = $this->campaignFacts($id);

        foreach ([['POST', \sprintf('/api/campaigns/%s/facts', $id)], ['PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId)]] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, $body);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => $error], $this->json());
        }

        self::assertSame($before, $this->campaignFacts($id));
    }

    #[Test]
    public function aFactCannotLinkToItselfNorBeEditedWithoutItsLabelOrText(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);
        $before = $this->campaignFacts($id);
        $uri = \sprintf('/api/campaigns/%s/facts/%s', $id, $factId);

        $this->client->jsonRequest('PUT', $uri, ['label' => 'L', 'text' => 'T', 'links' => [['kind' => 'fact', 'id' => $factId]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'A fact cannot link to itself.'], $this->json());

        foreach ([['text' => 'T', 'links' => []], ['label' => 'L', 'links' => []], ['label' => null, 'text' => null, 'links' => []], ['links' => []]] as $body) {
            $this->client->jsonRequest('PUT', $uri, $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'A free fact needs both its label and its text.'], $this->json());
        }

        self::assertSame($before, $this->campaignFacts($id));
    }

    #[Test]
    public function aSlotFactRefusesALabelOrText(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $slotFact = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7010';
        $this->seed($id, static function (Campaign $campaign, GameSystemSnapshot $release) use ($slotFact): void {
            $campaign->fillFactSlot(FactId::fromString($slotFact), 'city', FactFill::text('Ashford'), $release);
        });
        $before = $this->campaignFacts($id);

        foreach ([['label' => 'L'], ['text' => 'T'], ['label' => 'L', 'text' => 'T']] as $body) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $slotFact), $body + ['links' => []]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'A slot fact has no label or text of its own; fill its slot again to change its value.'], $this->json());
        }

        self::assertSame($before, $this->campaignFacts($id));
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);

        foreach ([$id, self::UNKNOWN_ID] as $campaignId) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $campaignId), ['label' => 'L', 'text' => 'T']);
            self::assertResponseStatusCodeSame(404);

            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $campaignId, $factId), ['label' => 'L', 'text' => 'T', 'links' => []]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertSame(['Guild'], array_column($this->campaignFacts($id), 'label'));
    }

    #[Test]
    public function anUnknownOrMalformedFactIdIsNotFoundAfterTheCampaignIsChecked(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', '%20'] as $factId) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), ['label' => 'L', 'text' => 'T', 'links' => []]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Fact "%s" not found.', rawurldecode($factId))], $this->json());
        }

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['label' => 'L', 'text' => 'T', 'links' => []]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => \sprintf('Campaign "%s" not found.', self::UNKNOWN_ID)], $this->json());
    }

    #[Test]
    public function aLinkToSomethingTheCampaignDoesNotHoldIsNotFoundAndNothingChanges(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);
        $before = $this->campaignFacts($id);

        foreach (['npc', 'thread', 'fact'] as $kind) {
            $body = ['label' => 'L', 'text' => 'T', 'links' => [['kind' => $kind, 'id' => self::UNKNOWN_ID]]];

            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), $body);
            self::assertResponseStatusCodeSame(404);

            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), $body);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertSame($before, $this->campaignFacts($id));
    }

    #[Test]
    public function aGeneratedIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(FactIdGenerator::class, new readonly class implements FactIdGenerator {
            public function generate(): FactId
            {
                return FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), ['label' => 'Other', 'text' => 'Text']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Fact "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['Guild'], array_column($this->campaignFacts($id), 'label'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReleases(): iterable
    {
        yield 'release gone' => ['DELETE FROM studio_gamesystem_release'];
        yield 'unsupported schema version' => ['UPDATE studio_gamesystem_release SET schema_version = 99'];
    }

    #[Test]
    #[DataProvider('unreadableReleases')]
    public function aPinnedReleaseThatCannotBeReadIsAServerErrorWithoutInternals(string $breakTheRelease): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);
        $this->connection()->executeStatement($breakTheRelease);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/facts', $id)],
            ['PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId)],
        ] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['label' => 'L', 'text' => 'T', 'links' => []]);

            self::assertResponseStatusCodeSame(500);
            self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(facts) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAnotherFact(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->seed($id, static function (Campaign $campaign, GameSystemSnapshot $release): void {
            for ($i = 1; $i <= Campaign::MAX_FACTS; ++$i) {
                $campaign->addFreeFact(FactId::fromString('fact-'.$i), 'Fact '.$i, 'Text', [], $release);
            }
        });

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $id), ['label' => 'One too many', 'text' => 'Text']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 facts.'], $this->json());
        self::assertCount(500, $this->campaignFacts($id));
    }

    #[Test]
    public function aChangeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $factId = $this->addFact($id, ['label' => 'Guild', 'text' => 'Owes a debt']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/facts', $id), ['label' => 'Other', 'text' => 'Text']],
            ['PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $factId), ['label' => 'Renamed', 'text' => 'Text', 'links' => []]],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([['Guild', 'Owes a debt']], array_map(static fn (array $fact): array => [$fact['label'], $fact['text']], $this->campaignFacts($id)));
    }

    #[Test]
    public function factEndpointsAreForSoloPlayersOnly(): void
    {
        $requests = [
            ['POST', '/api/campaigns/'.self::UNKNOWN_ID.'/facts', ['label' => 'L', 'text' => 'T']],
            ['PUT', \sprintf('/api/campaigns/%s/facts/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['label' => 'L', 'text' => 'T', 'links' => []]],
            ['PUT', \sprintf('/api/campaigns/%s/fact-slots/city', self::UNKNOWN_ID), ['text' => 'T']],
        ];

        foreach ($requests as [$method, $uri, $body]) {
            $this->client->jsonRequest($method, $uri, $body);
            self::assertResponseStatusCodeSame(401);
        }

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            foreach ($requests as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(403);
                self::assertSame(['error' => 'Access denied.'], $this->json());
            }
        }
    }

    #[Test]
    public function aTextSlotIsFilledAndFilledAgainKeepingItsFactAndLinks(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => '  Ashford ']);

        self::assertResponseIsSuccessful();
        $fact = $this->json();
        self::assertIsString($fact['id'] ?? null);
        self::assertSame(['slot' => 'city', 'label' => 'City', 'text' => 'Ashford', 'subject' => null, 'value' => 'Ashford', 'links' => []], array_diff_key($fact, ['id' => 1]));
        self::assertSame([$fact], $this->campaignFacts($id));

        // Editing the slot fact's links goes through the fact endpoint; a refill keeps them.
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/facts/%s', $id, $fact['id']), ['links' => [['kind' => 'npc', 'id' => $npcId]]]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => 'Brightwater']);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $fact['id'], 'slot' => 'city', 'label' => 'City', 'text' => 'Brightwater', 'subject' => null, 'value' => 'Brightwater', 'links' => [['kind' => 'npc', 'id' => $npcId, 'name' => 'Mira']]], $this->json());
        self::assertSame([$this->json()], $this->campaignFacts($id));
    }

    #[Test]
    public function anNpcSlotTakesAnExistingNpcOrANewOneByName(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/prince', $id), ['subject' => $npcId]);

        self::assertResponseIsSuccessful();
        self::assertSame(['slot' => 'prince', 'label' => 'Prince', 'text' => null, 'subject' => ['kind' => 'npc', 'id' => $npcId, 'name' => 'Mira'], 'value' => 'Mira', 'links' => []], array_diff_key($this->json(), ['id' => 1]));

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/prince', $id), ['name' => ' Valen ']);

        self::assertResponseIsSuccessful();
        $subject = $this->json()['subject'] ?? null;
        self::assertIsArray($subject);
        self::assertSame(['npc', 'Valen'], [$subject['kind'], $subject['name']]);
        self::assertNotSame($npcId, $subject['id']);
        $this->client->request('GET', '/api/campaigns/'.$id);
        $npcs = $this->json()['npcs'] ?? null;
        self::assertIsArray($npcs);
        self::assertSame(['Mira', 'Valen'], array_column($npcs, 'name'));
        $valen = $npcs[1];
        self::assertIsArray($valen);
        self::assertSame(['open', 1], [$valen['status'], $valen['weight']]);
        self::assertCount(1, $this->campaignFacts($id));
    }

    #[Test]
    public function aThreadSlotTakesAnExistingThreadOrANewOneByName(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $threadId = $this->addThread($id);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/goal', $id), ['subject' => $threadId]);
        self::assertResponseIsSuccessful();
        self::assertSame('Vault', $this->json()['value'] ?? null);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/goal', $id), ['name' => 'Find the ledger']);

        self::assertResponseIsSuccessful();
        self::assertSame('Find the ledger', $this->json()['value'] ?? null);
        $this->client->request('GET', '/api/campaigns/'.$id);
        $threads = $this->json()['threads'] ?? null;
        self::assertIsArray($threads);
        self::assertSame(['Vault', 'Find the ledger'], array_column($threads, 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFills(): iterable
    {
        yield 'not an object' => ['[3]'];
        yield 'an empty list' => ['[]'];
        yield 'invalid JSON' => ['{"text":'];
        yield 'a numeric text' => ['{"text": 4}'];
        yield 'a numeric subject' => ['{"subject": 4}'];
        yield 'a subject that is not a UUID' => ['{"subject": "npc-1"}'];
        yield 'a list as name' => ['{"name": ["Valen"]}'];
    }

    #[Test]
    #[DataProvider('malformedFills')]
    public function aMalformedFillIsABadRequestAndNothingChanges(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('PUT', \sprintf('/api/campaigns/%s/fact-slots/prince', $id), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => self::MALFORMED_FILL], $this->json());
        self::assertSame([], $this->campaignFacts($id));
    }

    #[Test]
    public function aFormEncodedFillIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => 'Ashford']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the fact slot value as JSON.'], $this->json());
        self::assertSame([], $this->campaignFacts($id));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidFills(): iterable
    {
        yield 'an unknown slot' => ['nowhere', ['text' => 'T'], 'Fact slot "nowhere" not found in the pinned release.'];
        yield 'no value' => ['city', ['other' => 'Ashford'], 'Send exactly one of text, subject and name.'];
        yield 'two values' => ['prince', ['subject' => self::UNKNOWN_ID, 'name' => 'Valen'], 'Send exactly one of text, subject and name.'];
        yield 'a text on an npc slot' => ['prince', ['text' => 'Valen'], 'A "npc" slot takes an NPC.'];
        yield 'a text on a thread slot' => ['goal', ['text' => 'Find it'], 'A "thread" slot takes a Thread.'];
        yield 'a subject on a text slot' => ['city', ['subject' => self::UNKNOWN_ID], 'A "text" slot takes a text.'];
        yield 'a name on a text slot' => ['city', ['name' => 'Ashford'], 'A "text" slot takes a text.'];
        yield 'a blank text' => ['city', ['text' => " \n"], 'The text must not be blank.'];
        yield 'a too long text' => ['city', ['text' => str_repeat('a', 2001)], 'The text must be at most 2000 characters, got 2001.'];
        yield 'a blank NPC name' => ['prince', ['name' => '  '], 'The name must not be blank.'];
        yield 'a too long Thread name' => ['goal', ['name' => str_repeat('a', 201)], 'The name must be at most 200 characters, got 201.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidFills')]
    public function anInvalidFillIsUnprocessableAndNothingChanges(string $slot, array $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->fillSlot($id, 'city', ['text' => 'Ashford']);
        $before = $this->campaignFacts($id);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/%s', $id, $slot), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        self::assertSame($before, $this->campaignFacts($id));
        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertSame([], $this->json()['npcs'] ?? null);
        self::assertSame([], $this->json()['threads'] ?? null);
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignOrSubjectIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);

        foreach ([$id, self::UNKNOWN_ID] as $campaignId) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $campaignId), ['text' => 'Ashford']);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
        }

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        foreach (['prince' => 'NPC', 'goal' => 'Thread'] as $slot => $kind) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/%s', $id, $slot), ['subject' => self::UNKNOWN_ID]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('%s "%s" not found.', $kind, self::UNKNOWN_ID)], $this->json());
        }

        self::assertSame([], $this->campaignFacts($id));
    }

    #[Test]
    public function aCampaignAtTheLimitOfFactsOrNpcsRefusesAFillThatWouldAddOne(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->seed($id, static function (Campaign $campaign, GameSystemSnapshot $release): void {
            for ($i = 1; $i <= Campaign::MAX_FACTS; ++$i) {
                $campaign->addFreeFact(FactId::fromString('fact-'.$i), 'Fact '.$i, 'Text', [], $release);
            }
        });

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => 'Ashford']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 facts.'], $this->json());
        self::assertCount(500, $this->campaignFacts($id));

        $other = $this->createCampaign();
        $this->seed($other, static function (Campaign $campaign, GameSystemSnapshot $release): void {
            for ($i = 1; $i <= 500; ++$i) {
                $campaign->addNpc(NpcId::fromString('npc-'.$i), 'NPC '.$i, null, [], [], 1, $release);
            }
        });

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/prince', $other), ['name' => 'One too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 500 NPCs.'], $this->json());
        self::assertSame([], $this->campaignFacts($other));
    }

    #[Test]
    public function aGeneratedFactIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(FactIdGenerator::class, new readonly class implements FactIdGenerator {
            public function generate(): FactId
            {
                return FactId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->fillSlot($id, 'city', ['text' => 'Ashford']);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/ambition', $id), ['text' => 'Power']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Fact "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['City'], array_column($this->campaignFacts($id), 'label'));
    }

    #[Test]
    public function aFillOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->requestWhileAnotherRequestSaves($id, 'PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => 'Ashford']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        self::assertSame([], $this->campaignFacts($id));
    }

    #[Test]
    #[DataProvider('unreadableReleases')]
    public function aFillOnAPinnedReleaseThatCannotBeReadIsAServerErrorWithoutInternals(string $breakTheRelease): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->connection()->executeStatement($breakTheRelease);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/city', $id), ['text' => 'Ashford']);

        self::assertResponseStatusCodeSame(500);
        self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
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
     * @return list<array<string, mixed>> the facts of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignFacts(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $facts = $this->json()['facts'] ?? null;
        self::assertIsArray($facts);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($facts);

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addFact(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/facts', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);

        return $this->idOfResponse();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fillSlot(string $campaignId, string $slot, array $body): void
    {
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/fact-slots/%s', $campaignId, $slot), $body);
        self::assertResponseIsSuccessful();
    }

    private function addNpc(string $campaignId): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $campaignId), ['name' => 'Mira']);
        self::assertResponseStatusCodeSame(201);

        return $this->idOfResponse();
    }

    private function addThread(string $campaignId): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/threads', $campaignId), ['name' => 'Vault']);
        self::assertResponseStatusCodeSame(201);

        return $this->idOfResponse();
    }

    private function idOfResponse(): string
    {
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /**
     * Seeds the campaign through the domain, for states the API cannot reach cheaply (500 facts).
     *
     * @param callable(Campaign, GameSystemSnapshot): void $change
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
