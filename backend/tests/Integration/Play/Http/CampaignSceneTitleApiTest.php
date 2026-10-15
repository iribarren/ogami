<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
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
 * `PUT /api/campaigns/{campaignId}/scenes/current/title` on a campaign played freely, in a scene:
 * the player renames the current scene, and every refusal leaves the title as it was.
 */
#[CoversClass(CampaignController::class)]
final class CampaignSceneTitleApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9811';
    private const string URL = '/api/campaigns/%s/scenes/current/title';

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
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9313', PeopleReleases::content() + ['sheet' => []], false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theTitleIsTrimmedKeptAndAnsweredWithTheScene(): void
    {
        $this->inScene();

        $this->put(['title' => '  The letter  ']);

        self::assertResponseStatusCodeSame(200);
        $scene = $this->json();
        self::assertSame([1, 'The letter'], [$scene['number'], $scene['title']]);
        self::assertSame($scene, $this->currentScene());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> a body and the error
     */
    public static function refusedTitles(): iterable
    {
        yield 'a blank title' => [['title' => '   '], 'A scene title must not be blank.'];
        yield 'an empty title' => [['title' => ''], 'A scene title must not be blank.'];
        yield 'a title of 101 characters' => [['title' => '€€'.str_repeat('a', 99)], 'A scene title must be at most 100 characters, got 101.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedTitles')]
    public function aTitleTheSceneCannotTakeIsUnprocessableAndChangesNothing(array $body, string $message): void
    {
        $this->inScene();
        $before = $this->campaign();

        $this->put($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $this->error());
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aTitleOfOneHundredCharactersIsAccepted(): void
    {
        $this->inScene();
        $title = str_repeat('é', 100);

        $this->put(['title' => $title]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($title, $this->json()['title']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}> a body refused as malformed
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['{oops'];
        yield 'not an object' => ['"The letter"'];
        yield 'no title' => [[]];
        yield 'a number' => [['title' => 1]];
        yield 'a list' => [['title' => ['The letter']]];
        yield 'a null' => [['title' => null]];
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
        self::assertStringStartsWith('Send a JSON object with a string "title"', $this->error());
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->inScene();

        $this->client->request('PUT', \sprintf(self::URL, self::CAMPAIGN), server: ['CONTENT_TYPE' => 'text/plain'], content: 'title=The letter');

        self::assertResponseStatusCodeSame(415);
    }

    #[Test]
    public function aCampaignThatDoesNotExistIsNotFound(): void
    {
        foreach (['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9899', 'not-an-id'] as $id) {
            $this->client->jsonRequest('PUT', \sprintf(self::URL, $id), ['title' => 'The letter']);

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

        $this->put(['title' => 'The letter']);

        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function withoutACurrentSceneThereIsNothingToRename(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The people', 'people', null));
        $this->put(['title' => 'The letter']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Start a scene before renaming it.', $this->error());

        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->put(['title' => 'The letter']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Start a scene before renaming it.', $this->error());
    }

    #[Test]
    public function aCampaignSavedByAnotherRequestMeanwhileIsAConflictAndKeepsTheTitle(): void
    {
        $this->inScene();
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
            $this->put(['title' => 'The letter']);
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
