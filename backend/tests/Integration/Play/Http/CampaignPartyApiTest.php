<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddNpc;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\EndSession;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\SceneResponse;
use App\Play\Infrastructure\Http\SessionResponse;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\PeopleReleases;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The party of the session under way (`PUT /api/campaigns/{campaignId}/sessions/current/party`)
 * and the Characters in a Scene's cast (`PUT .../scenes/current/cast` with `characterIds`), on a
 * campaign with the Characters Vi and Jax and the NPC Mira: a new scene and the next session start
 * from the party, and a new or promoted Character joins both.
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(SessionResponse::class)]
#[CoversClass(SceneResponse::class)]
final class CampaignPartyApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9901';
    private const string PARTY_URL = '/api/campaigns/%s/sessions/current/party';
    private const string CAST_URL = '/api/campaigns/%s/scenes/current/cast';
    private const string MALFORMED = 'Send a JSON object with a list of strings "characterIds", such as {"characterIds": ["0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"]}.';

    private KernelBrowser $client;
    private CommandBus $commands;
    private string $userId;
    private string $vi;
    private string $jax;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-10T09:00:00+00:00'));
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9313', PeopleReleases::content() + ['sheet' => []], false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', null));
        $this->dispatch(new AddNpc(self::CAMPAIGN, $this->userId, 'npc-mira', 'Mira', null, [], [], 1));
        $this->vi = $this->createCharacter('Vi');
        $this->jax = $this->createCharacter('Jax');
    }

    #[Test]
    public function thePlayerSetsThePartyInTheOrderSentAndGetsTheSessionBack(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));

        $this->putParty(['characterIds' => [$this->jax, $this->vi]]);

        self::assertResponseStatusCodeSame(200);
        $session = $this->json();
        self::assertSame(1, $session['number']);
        self::assertSame([['id' => $this->jax, 'name' => 'Jax'], ['id' => $this->vi, 'name' => 'Vi']], $session['party']);
        self::assertSame($session, $this->sessions()[0]);

        $this->putParty(['characterIds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['party']);
    }

    #[Test]
    public function theNextSessionStartsWithThePartyOfTheLastAndAnEndedSessionKeepsItsOwn(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->putParty(['characterIds' => [$this->vi]]);
        $this->dispatch(new EndSession(self::CAMPAIGN, $this->userId, 1));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));

        $sessions = $this->sessions();
        self::assertSame([[['id' => $this->vi, 'name' => 'Vi']], [['id' => $this->vi, 'name' => 'Vi']]], [$sessions[0]['party'], $sessions[1]['party']]);

        $this->putParty(['characterIds' => [$this->jax]]);

        $sessions = $this->sessions();
        self::assertSame([['id' => $this->vi, 'name' => 'Vi']], $sessions[0]['party']);
        self::assertSame([['id' => $this->jax, 'name' => 'Jax']], $sessions[1]['party']);
    }

    #[Test]
    public function theFirstSessionStartsWithAnEmptyPartyAndANewSceneStartsWithTheParty(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        self::assertSame([], $this->sessions()[0]['party']);

        $this->putParty(['characterIds' => [$this->vi, $this->jax]]);
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        $this->putParty(['characterIds' => [$this->jax]]);
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'Inside'));

        $scenes = $this->scenes(0);
        self::assertSame([['id' => $this->vi, 'name' => 'Vi'], ['id' => $this->jax, 'name' => 'Jax']], $scenes[0]['characters']);
        self::assertSame([['id' => $this->jax, 'name' => 'Jax']], $scenes[1]['characters']);
        self::assertSame([], $scenes[1]['cast']);
    }

    #[Test]
    public function aNewCharacterJoinsThePartyAndTheCurrentSceneOnlyWhileASessionIsUnderWay(): void
    {
        $before = $this->createCharacter('Early');
        self::assertSame([], $this->sessions());

        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        $joined = $this->createCharacter('Late');

        $session = $this->sessions()[0];
        self::assertSame([['id' => $joined, 'name' => 'Late']], $session['party']);
        self::assertSame([['id' => $joined, 'name' => 'Late']], $this->scenes(0)[0]['characters']);

        $this->dispatch(new EndSession(self::CAMPAIGN, $this->userId, 1));
        $this->createCharacter('After');

        self::assertSame([['id' => $joined, 'name' => 'Late']], $this->sessions()[0]['party']);
        self::assertNotSame($before, $joined);
    }

    #[Test]
    public function promotingAnNpcSwapsItForItsCharacterInTheCurrentSceneAndJoinsTheParty(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'Earlier'));
        $this->putCast(['npcIds' => ['npc-mira']]);
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        $this->putCast(['npcIds' => ['npc-mira']]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/npc-mira/promote', self::CAMPAIGN));

        self::assertResponseStatusCodeSame(201);
        $promoted = $this->json()['id'];
        self::assertIsString($promoted);
        $session = $this->sessions()[0];
        self::assertSame([['id' => $promoted, 'name' => 'Mira']], $session['party']);
        self::assertSame([[], [['id' => $promoted, 'name' => 'Mira']]], [$this->scenes(0)[1]['cast'], $this->scenes(0)[1]['characters']]);
        // The scene before keeps its NPC and gains no Character.
        self::assertSame([[['id' => 'npc-mira', 'name' => 'Mira']], []], [$this->scenes(0)[0]['cast'], $this->scenes(0)[0]['characters']]);
    }

    #[Test]
    public function promotingAnNpcWithoutASessionChangesNoPartyOrCast(): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/npc-mira/promote', self::CAMPAIGN));

        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->sessions());
    }

    #[Test]
    public function theCastEditSetsTheCharactersWhenGivenAndKeepsThemWhenAbsentOrNull(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        $this->putParty(['characterIds' => [$this->vi]]);
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'Inside'));

        $this->putCast(['npcIds' => ['npc-mira'], 'characterIds' => [$this->jax, $this->vi]]);

        self::assertResponseStatusCodeSame(200);
        $scene = $this->json();
        self::assertSame([['id' => 'npc-mira', 'name' => 'Mira']], $scene['cast']);
        self::assertSame([['id' => $this->jax, 'name' => 'Jax'], ['id' => $this->vi, 'name' => 'Vi']], $scene['characters']);

        $this->putCast(['npcIds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['cast']);
        self::assertSame($scene['characters'], $this->json()['characters']);

        $this->putCast(['npcIds' => [], 'characterIds' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($scene['characters'], $this->json()['characters']);

        $this->putCast(['npcIds' => [], 'characterIds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['characters']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function refusedParties(): iterable
    {
        yield 'an unknown Character' => [['characterIds' => ['nobody']], 404, 'Character "nobody" not found.'];
        yield 'a blank id' => [['characterIds' => ['  ']], 404, 'A Character id must not be blank.'];
        yield 'a Character twice' => [['characterIds' => ['@vi', '@jax', '@vi']], 422, 'Character "@vi" is in the party twice.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedParties')]
    public function aPartyTheCampaignCannotTakeIsRefusedAndChangesNothing(array $body, int $status, string $message): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->putParty(['characterIds' => [$this->jax]]);
        $before = $this->campaign();

        $this->putParty($this->withIds($body));

        self::assertResponseStatusCodeSame($status);
        self::assertSame($this->withIds(['error' => $message]), $this->json());
        self::assertSame($before, $this->campaign());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function malformedParties(): iterable
    {
        yield 'no characterIds' => [[]];
        yield 'characterIds null' => [['characterIds' => null]];
        yield 'characterIds a string' => [['characterIds' => 'a']];
        yield 'characterIds not strings' => [['characterIds' => [1]]];
        yield 'characterIds an object' => [['characterIds' => ['a' => 'b']]];
        yield 'a JSON list' => ['[]'];
        yield 'broken JSON' => ['{"characterIds": ['];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[Test]
    #[DataProvider('malformedParties')]
    public function aMalformedPartyIsABadRequestAndChangesNothing(array|string $body): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $before = $this->campaign();

        if (\is_string($body)) {
            $this->client->request('PUT', \sprintf(self::PARTY_URL, self::CAMPAIGN), server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        } else {
            $this->putParty($body);
        }

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => self::MALFORMED], $this->json());
        self::assertSame($before, $this->campaign());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function refusedCasts(): iterable
    {
        yield 'an unknown Character' => [['npcIds' => [], 'characterIds' => ['nobody']], 404, 'Character "nobody" not found.'];
        yield 'a blank Character id' => [['npcIds' => [], 'characterIds' => [' ']], 404, 'A Character id must not be blank.'];
        yield 'a Character twice' => [['npcIds' => ['npc-mira'], 'characterIds' => ['@vi', '@vi']], 422, 'Character "@vi" is in the cast twice.'];
        yield 'characterIds a string' => [['npcIds' => [], 'characterIds' => 'a'], 400, ''];
        yield 'characterIds not strings' => [['npcIds' => [], 'characterIds' => [1]], 400, ''];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedCasts')]
    public function aCastWithCharactersTheCampaignCannotTakeIsRefusedAndChangesNothing(array $body, int $status, string $message): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        $this->putCast(['npcIds' => [], 'characterIds' => [$this->jax]]);
        $before = $this->campaign();

        $this->putCast($this->withIds($body));

        self::assertResponseStatusCodeSame($status);
        if ('' !== $message) {
            self::assertSame($this->withIds(['error' => $message]), $this->json());
        }

        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));

        $this->client->request('PUT', \sprintf(self::PARTY_URL, self::CAMPAIGN), server: ['CONTENT_TYPE' => 'text/plain'], content: 'characterIds');

        self::assertResponseStatusCodeSame(415);
    }

    #[Test]
    public function withoutASessionUnderWayThereIsNoPartyToEdit(): void
    {
        $this->putParty(['characterIds' => [$this->vi]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No session is under way: start a session before editing its party.'], $this->json());

        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new EndSession(self::CAMPAIGN, $this->userId, 1));
        $this->putParty(['characterIds' => [$this->vi]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->sessions()[0]['party']);
    }

    #[Test]
    public function anUnknownOrForeignCampaignIsNotFound(): void
    {
        $this->client->jsonRequest('PUT', \sprintf(self::PARTY_URL, 'nobody'), ['characterIds' => []]);
        self::assertResponseStatusCodeSame(404);

        $this->dispatch(new CreateUser('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9902', 'bob@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'bob@example.com', 'password' => self::PASSWORD]);
        $this->putParty(['characterIds' => []]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aCampaignSavedByAnotherRequestMeanwhileIsAConflictAndKeepsTheParty(): void
    {
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->putParty(['characterIds' => [$this->jax]]);
        $before = $this->campaign();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($entityManager->getConnection(), self::CAMPAIGN) {
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
            $this->putParty(['characterIds' => [$this->vi]]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(\sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN), $this->json()['error']);
        self::assertSame($before, $this->campaign());
    }

    /**
     * Replaces the placeholders "@vi" and "@jax" in the ids and messages of a body by the real ids.
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function withIds(array $body): array
    {
        $decoded = json_decode(str_replace(['@vi', '@jax'], [$this->vi, $this->jax], json_encode($body, \JSON_THROW_ON_ERROR)), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createCharacter(string $name): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', self::CAMPAIGN), ['name' => $name]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $id;
    }

    /**
     * @param array<mixed> $body
     */
    private function putParty(array $body): void
    {
        $this->client->jsonRequest('PUT', \sprintf(self::PARTY_URL, self::CAMPAIGN), $body);
    }

    /**
     * @param array<mixed> $body
     */
    private function putCast(array $body): void
    {
        $this->client->jsonRequest('PUT', \sprintf(self::CAST_URL, self::CAMPAIGN), $body);
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return array<mixed>
     */
    private function campaign(): array
    {
        $this->client->request('GET', '/api/campaigns/'.self::CAMPAIGN);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<array<mixed>>
     */
    private function sessions(): array
    {
        $sessions = $this->campaign()['sessions'];
        self::assertIsArray($sessions);
        $list = [];
        foreach ($sessions as $session) {
            self::assertIsArray($session);
            $list[] = $session;
        }

        return $list;
    }

    /**
     * @return list<array<mixed>> the scenes of the session at this index of the campaign's sessions
     */
    private function scenes(int $index): array
    {
        $scenes = $this->sessions()[$index]['scenes'];
        self::assertIsArray($scenes);
        $list = [];
        foreach ($scenes as $scene) {
            self::assertIsArray($scene);
            $list[] = $scene;
        }

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
