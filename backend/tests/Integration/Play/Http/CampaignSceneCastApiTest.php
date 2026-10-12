<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddNpc;
use App\Play\Application\Clock;
use App\Play\Application\CloseNpc;
use App\Play\Application\CreateCampaign;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\CampaignController;
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
 * `PUT /api/campaigns/{campaignId}/scenes/current/cast` on a campaign played freely, in a scene,
 * with the NPCs Ana, Bea and Cid (closed): the edit replaces the whole cast, in the order sent, and
 * every refusal leaves the cast as it was.
 */
#[CoversClass(CampaignController::class)]
final class CampaignSceneCastApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9801';
    private const string URL = '/api/campaigns/%s/scenes/current/cast';

    private KernelBrowser $client;
    private CommandBus $commands;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-10T09:00:00+00:00'));
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9312', PeopleReleases::content() + ['sheet' => []], false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theEditReplacesTheCastInTheOrderSentClosedNpcsIncludedAndAnswersWithTheScene(): void
    {
        $this->inScene();

        $this->put(['npcIds' => ['npc-cid', 'npc-ana']]);

        self::assertResponseStatusCodeSame(200);
        $scene = $this->json();
        self::assertSame([1, 'At the gate'], [$scene['number'], $scene['title']]);
        self::assertSame([['id' => 'npc-cid', 'name' => 'Cid'], ['id' => 'npc-ana', 'name' => 'Ana']], $scene['cast']);
        self::assertSame($scene, $this->currentScene());

        $this->put(['npcIds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['cast']);
        self::assertSame([], $this->currentScene()['cast']);
    }

    #[Test]
    public function aSceneStartedWithoutAnEditHasAnEmptyCast(): void
    {
        $this->inScene();

        self::assertSame([], $this->currentScene()['cast']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}> a body, the status and the start of the error
     */
    public static function refusedBodies(): iterable
    {
        yield 'an unknown NPC' => [['npcIds' => ['npc-ana', 'nobody']], 404, 'NPC "nobody" not found.'];
        yield 'a malformed id' => [['npcIds' => ['not-a-uuid']], 404, 'NPC "not-a-uuid" not found.'];
        yield 'a blank id' => [['npcIds' => ['  ']], 404, 'An NPC id must not be blank.'];
        yield 'an NPC twice' => [['npcIds' => ['npc-ana', 'npc-bea', 'npc-ana']], 422, 'NPC "npc-ana" is in the cast twice.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedBodies')]
    public function aCastTheCampaignCannotTakeIsRefusedAndChangesNothing(array $body, int $status, string $message): void
    {
        $this->inScene();
        $this->put(['npcIds' => ['npc-bea']]);
        $before = $this->campaign();

        $this->put($body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($message, $this->error());
        self::assertSame($before, $this->campaign());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}> a body refused as malformed
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['{oops'];
        yield 'not an object' => ['"npc-ana"'];
        yield 'no list' => [[]];
        yield 'a string' => [['npcIds' => 'npc-ana']];
        yield 'an object' => [['npcIds' => ['a' => 'npc-ana']]];
        yield 'a number in the list' => [['npcIds' => ['npc-ana', 1]]];
        yield 'a null' => [['npcIds' => null]];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndChangesNothing(array|string $body): void
    {
        $this->inScene();
        $before = $this->campaign();

        \is_string($body)
            ? $this->client->request('PUT', \sprintf(self::URL, self::CAMPAIGN), server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            : $this->put($body);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('Send a JSON object with a list of strings "npcIds"', $this->error());
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->inScene();

        $this->client->request('PUT', \sprintf(self::URL, self::CAMPAIGN), server: ['CONTENT_TYPE' => 'text/plain'], content: 'npcIds=npc-ana');

        self::assertResponseStatusCodeSame(415);
    }

    #[Test]
    public function aCampaignThatDoesNotExistIsNotFound(): void
    {
        foreach (['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9899', 'not-an-id'] as $id) {
            $this->client->jsonRequest('PUT', \sprintf(self::URL, $id), ['npcIds' => []]);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString($id, $this->error());
        }
    }

    #[Test]
    public function aCampaignOfAnotherPlayerIsNotFound(): void
    {
        $this->inScene();
        $before = $this->campaign();
        $this->client->request('POST', '/api/auth/logout');
        $other = self::getContainer()->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($other, 'grace@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'grace@example.com', 'password' => self::PASSWORD]);

        $this->put(['npcIds' => ['npc-ana']]);

        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function withoutACurrentSceneThereIsNoCastToEdit(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', null));
        $this->put(['npcIds' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Start a scene before editing its cast.', $this->error());

        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->put(['npcIds' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Start a scene before editing its cast.', $this->error());
    }

    #[Test]
    public function aCampaignSavedByAnotherRequestMeanwhileIsAConflictAndKeepsTheCast(): void
    {
        $this->inScene();
        $this->put(['npcIds' => ['npc-bea']]);
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
            $this->put(['npcIds' => ['npc-ana']]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(\sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN), $this->error());
        self::assertSame($before, $this->campaign());
    }

    private function inScene(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', null));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'At the gate'));
        foreach ([['ana', 'Ana'], ['bea', 'Bea'], ['cid', 'Cid']] as [$id, $name]) {
            $this->dispatch(new AddNpc(self::CAMPAIGN, $this->userId, 'npc-'.$id, $name, null, [], [], 1));
        }

        $this->dispatch(new CloseNpc(self::CAMPAIGN, $this->userId, 'npc-cid'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(array $body): void
    {
        $this->client->jsonRequest('PUT', \sprintf(self::URL, self::CAMPAIGN), $body);
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
     * @return array<mixed>
     */
    private function currentScene(): array
    {
        $sessions = $this->campaign()['sessions'];
        self::assertIsArray($sessions);
        $session = array_last($sessions);
        self::assertIsArray($session);
        $scenes = $session['scenes'];
        self::assertIsArray($scenes);
        $scene = array_last($scenes);
        self::assertIsArray($scene);

        return $scene;
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
