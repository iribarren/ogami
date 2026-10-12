<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddNpc;
use App\Play\Application\AddThread;
use App\Play\Application\Clock;
use App\Play\Application\CloseNpc;
use App\Play\Application\CreateCampaign;
use App\Play\Application\PickSceneType;
use App\Play\Application\StartSession;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Infrastructure\Http\FlowRunController;
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
 * The NPC and Thread parts of the FlowRun over the Play API, on the release "people"
 * (PeopleReleases): what the answer endpoint does with the create Effects and the pick steps.
 */
#[CoversClass(FlowRunController::class)]
final class CampaignFlowPicksApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9701';

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
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9311', PeopleReleases::content() + ['sheet' => []], false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aCreateNpcWhoseGeneratedIdIsTakenIsAConflictAndChangesNothing(): void
    {
        $this->inScene('spawn');
        $this->dispatch(new AddNpc(self::CAMPAIGN, $this->userId, GeneratedItemId::npc(self::CAMPAIGN, 1)->toString(), 'Squatter', null, [], [], 1));
        $before = $this->campaign();

        $this->answer(['stepKey' => 'ask', 'text' => 'Vega']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already', $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aCreateThreadWhoseGeneratedIdIsTakenIsAConflictAndChangesNothing(): void
    {
        $this->inScene('spawn-thread');
        $this->dispatch(new AddThread(self::CAMPAIGN, $this->userId, GeneratedItemId::thread(self::CAMPAIGN, 1)->toString(), 'Squatter', [], [], 1));
        $before = $this->campaign();

        $this->answer(['stepKey' => 'ask', 'text' => 'The ledger']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already', $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    /**
     * @return iterable<string, array{string, string, string, list<array{id: string, name: string}>}> the Scene Type, the mode, the tags shown and the candidates, with ana, bea, cid and dax as set up by withPeople()
     */
    public static function pickSteps(): iterable
    {
        yield 'choose: the open rivals, in list order' => ['choose', 'choose', 'rival', [['id' => 'npc-ana', 'name' => 'Ana'], ['id' => 'npc-bea', 'name' => 'Bea']]];
        yield 'random: every open NPC' => ['draw', 'random', '', [['id' => 'npc-ana', 'name' => 'Ana'], ['id' => 'npc-bea', 'name' => 'Bea'], ['id' => 'npc-cid', 'name' => 'Cid']]];
    }

    /**
     * @param list<array{id: string, name: string}> $candidates
     */
    #[Test]
    #[DataProvider('pickSteps')]
    public function aPickStepShowsItsModeCountTagsAndCandidates(string $sceneType, string $mode, string $tags, array $candidates): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));

        $step = $this->campaign();

        self::assertSame('pick', self::at($step, 'flowRun', 'step', 'kind'));
        self::assertSame(
            ['npc', $mode, 1, 'choose' === $mode ? 2 : 1, '' === $tags ? [] : [['key' => 'rival', 'label' => 'Rival']], $candidates],
            array_map(static fn (string $key): mixed => self::at($step, 'flowRun', 'step', 'pick', $key), ['of', 'mode', 'min', 'max', 'tags', 'candidates']),
        );
    }

    #[Test]
    public function aStepOfAnotherKindHasNoPick(): void
    {
        $this->inScene('spawn');

        $campaign = $this->campaign();

        self::assertSame(['prompt', null], [self::at($campaign, 'flowRun', 'step', 'kind'), self::at($campaign, 'flowRun', 'step', 'pick')]);
    }

    #[Test]
    public function aPickStepWithNoMatchingNpcListsNoCandidates(): void
    {
        $this->inScene('choose');

        self::assertSame([], self::at($this->campaign(), 'flowRun', 'step', 'pick', 'candidates'));
    }

    #[Test]
    public function chosenIdsCompleteThePickRecordItInTheJournalAndJoinTheSceneCast(): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'choose'));

        $this->answer(['stepKey' => 'who', 'pickedIds' => ['npc-bea', 'npc-ana']]);

        self::assertResponseStatusCodeSame(200);
        $campaign = $this->json();
        self::assertSame('With Bea, Ana', self::at($campaign, 'flowRun', 'step', 'title'));
        self::assertSame([['id' => 'npc-bea', 'name' => 'Bea'], ['id' => 'npc-ana', 'name' => 'Ana']], self::at($this->currentScene($campaign), 'cast'));
        $entry = $this->journal()[0];
        self::assertSame(
            ['pick', 'npc', ['rival'], [['id' => 'npc-bea', 'name' => 'Bea'], ['id' => 'npc-ana', 'name' => 'Ana']]],
            array_map(static fn (string $key): mixed => self::at($entry, 'content', $key), ['kind', 'of', 'tags', 'picked']),
        );
        self::assertSame('pick', $entry['kind']);
    }

    #[Test]
    public function aRandomPickDrawsOnTheServerAndRecordsWhoWasDrawn(): void
    {
        $this->inScene('draw');
        $this->dispatch(new AddNpc(self::CAMPAIGN, $this->userId, 'npc-only', 'Only', null, [], [], 1));

        $this->answer(['stepKey' => 'who']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('With Only', self::at($this->json(), 'flowRun', 'step', 'title'));
        self::assertSame([['id' => 'npc-only', 'name' => 'Only']], self::at($this->journal()[0], 'content', 'picked'));
    }

    #[Test]
    public function aPickWithNoCandidatesCompletesAsNoneWithAnEmptyList(): void
    {
        $this->inScene('choose');

        $this->answer(['stepKey' => 'who', 'pickedIds' => []]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::at($this->journal()[0], 'content', 'picked'));
        self::assertSame([], self::at($this->currentScene($this->campaign()), 'cast'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> the Scene Type, a body and the error
     */
    public static function unprocessablePicks(): iterable
    {
        yield 'an id that is not a candidate' => ['choose', ['stepKey' => 'who', 'pickedIds' => ['npc-cid']], 'npc-cid'];
        yield 'an unknown id' => ['choose', ['stepKey' => 'who', 'pickedIds' => ['nobody']], 'nobody'];
        yield 'a closed NPC' => ['choose', ['stepKey' => 'who', 'pickedIds' => ['npc-dax']], 'npc-dax'];
        yield 'an id twice' => ['choose', ['stepKey' => 'who', 'pickedIds' => ['npc-ana', 'npc-ana']], 'npc-ana'];
        yield 'more than the maximum' => ['choose', ['stepKey' => 'who', 'pickedIds' => ['npc-ana', 'npc-bea', 'npc-ana']], 'npc-ana'];
        yield 'fewer than the minimum' => ['choose', ['stepKey' => 'who', 'pickedIds' => []], '1 to 2'];
        yield 'no ids in mode choose' => ['choose', ['stepKey' => 'who'], 'pickedIds'];
        yield 'ids in mode random' => ['draw', ['stepKey' => 'who', 'pickedIds' => ['npc-ana']], 'pickedIds'];
        yield 'ids for a prompt step' => ['spawn', ['stepKey' => 'ask', 'text' => 'Vega', 'pickedIds' => []], 'pickedIds'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessablePicks')]
    public function aPickTheStepCannotTakeIsUnprocessableAndChangesNothing(string $sceneType, array $body, string $message): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
        $before = $this->campaign();

        $this->answer($body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    /**
     * @return iterable<string, array{mixed}> a "pickedIds" the endpoint refuses as malformed
     */
    public static function malformedPickedIds(): iterable
    {
        yield 'a string' => ['npc-ana'];
        yield 'a number' => [1];
        yield 'an object' => [['a' => 'npc-ana']];
        yield 'a number in the list' => [['npc-ana', 1]];
        yield 'a null in the list' => [[null]];
        yield 'a list in the list' => [[['npc-ana']]];
    }

    #[Test]
    #[DataProvider('malformedPickedIds')]
    public function malformedPickedIdsAreABadRequestAndChangeNothing(mixed $pickedIds): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'choose'));
        $before = $this->campaign();

        $this->answer(['stepKey' => 'who', 'pickedIds' => $pickedIds]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('Send a JSON object', $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aPickAnsweredWhileAnotherRequestSavesTheCampaignIsAConflict(): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'choose'));
        $before = $this->campaign()['flowRun'];

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
            $this->answer(['stepKey' => 'who', 'pickedIds' => ['npc-ana']]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->campaign()['flowRun']);
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function theCastShowsTheNamesNowAndLegacySceneWithoutCastIsEmpty(): void
    {
        $this->withPeople();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'choose'));
        $this->answer(['stepKey' => 'who', 'pickedIds' => ['npc-ana']]);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/npcs/npc-ana', self::CAMPAIGN), ['name' => 'Ana Vega']);
        self::assertResponseStatusCodeSame(200);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/npc-ana/close', self::CAMPAIGN));
        self::assertResponseStatusCodeSame(200);

        $campaign = $this->campaign();

        self::assertSame([['id' => 'npc-ana', 'name' => 'Ana Vega']], self::at($this->currentScene($campaign), 'cast'));

        // A row stored before the cast existed has scenes without the key.
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE play_campaign SET sessions = sessions #- '{0,scenes,0,cast}' WHERE id = ?", [self::CAMPAIGN]);
        $stored = $connection->fetchOne('SELECT sessions FROM play_campaign WHERE id = ?', [self::CAMPAIGN]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('"cast"', $stored);

        self::assertSame([], self::at($this->currentScene($this->campaign()), 'cast'));
    }

    /**
     * A guided campaign in a scene with: Ana (rival), Bea (rival), Cid (ally) and Dax (rival, closed).
     */
    private function withPeople(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        foreach ([['ana', 'Ana', 'rival'], ['bea', 'Bea', 'rival'], ['cid', 'Cid', 'ally'], ['dax', 'Dax', 'rival']] as [$id, $name, $tag]) {
            $this->dispatch(new AddNpc(self::CAMPAIGN, $this->userId, 'npc-'.$id, $name, null, [$tag], [], 1));
        }

        $this->dispatch(new CloseNpc(self::CAMPAIGN, $this->userId, 'npc-dax'));
    }

    /**
     * @param array<mixed> $campaign
     *
     * @return array<mixed>
     */
    private function currentScene(array $campaign): array
    {
        $sessions = $campaign['sessions'];
        self::assertIsArray($sessions);
        $scenes = self::at(array_last($sessions), 'scenes');
        self::assertIsArray($scenes);
        $scene = array_last($scenes);
        self::assertIsArray($scene);

        return $scene;
    }

    /**
     * Reads a value down nested arrays, null when a key is missing.
     */
    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = \is_array($data) && \array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $data;
    }

    /**
     * A guided campaign of the Flow "tour", in a scene of this Scene Type.
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answer(array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/answer', self::CAMPAIGN), $body);
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
    private function journal(): array
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', self::CAMPAIGN));
        self::assertResponseIsSuccessful();

        return array_values(array_filter($this->json(), \is_array(...)));
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
