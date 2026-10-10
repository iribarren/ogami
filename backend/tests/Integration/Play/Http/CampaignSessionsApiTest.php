<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\SessionResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sessions as sittings over the Play API: ending the current session and starting the next.
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(SessionResponse::class)]
final class CampaignSessionsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container across requests, so the fixed clock below is the one the requests use.
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->clock = new FixedClock('2026-10-10T09:00:00+00:00');
        $container->set(Clock::class, $this->clock);
        $container->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9201', ReleaseViews::contractDocExampleContent(), false));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
    }

    #[Test]
    public function endingTheCurrentSessionReturnsItWithItsEndAndLeavesNoSessionUnderWay(): void
    {
        $id = $this->campaignInASession();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);
        self::assertResponseStatusCodeSame(201);

        $this->clock->moveTo('2026-10-10T12:00:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'number' => 1,
            'startedAt' => '2026-10-10T09:00:00+00:00',
            'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-10T09:00:00+00:00', 'kind' => 'scene', 'sceneType' => null, 'sceneTypeName' => null, 'hook' => null]],
            'endedAt' => '2026-10-10T12:00:00+00:00',
        ], $this->json());

        $campaign = $this->campaign($id);
        self::assertArrayHasKey('currentSessionNumber', $campaign);
        self::assertArrayHasKey('currentSceneNumber', $campaign);
        self::assertSame([null, null], [$campaign['currentSessionNumber'], $campaign['currentSceneNumber']]);
    }

    #[Test]
    public function nothingIsPlayedOnceTheSessionHasEndedUntilTheNextOneStarts(): void
    {
        $id = $this->campaignInASession();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'Too late']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a session before starting a scene.'], $this->json());

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => 'Too late']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a scene before recording a journal entry.'], $this->json());

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'Back at it']);
        self::assertResponseStatusCodeSame(201);

        $campaign = $this->json();
        self::assertSame([2, 1], [$campaign['currentSessionNumber'] ?? null, $campaign['currentSceneNumber'] ?? null]);
        self::assertIsArray($campaign['sessions'] ?? null);
        self::assertSame(['2026-10-10T09:00:00+00:00', null], array_map(static fn (mixed $session): mixed => \is_array($session) ? $session['endedAt'] : 'missing', $campaign['sessions']));
    }

    #[Test]
    public function anEndedSessionCannotEndAgain(): void
    {
        $id = $this->campaignInASession();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No session is under way: start a session before ending one.'], $this->json());
    }

    #[Test]
    public function endingASessionOfACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $id = $this->campaignInASession();

        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($entityManager->getConnection(), $id) {
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
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        $sessions = $this->campaign($id)['sessions'] ?? null;
        self::assertIsArray($sessions);
        self::assertSame([null], array_map(static fn (mixed $session): mixed => \is_array($session) ? $session['endedAt'] : 'missing', $sessions));
    }

    #[Test]
    public function noSessionEndsBeforeTheFirstOne(): void
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'example-journal']);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $id));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No session is under way: start a session before ending one.'], $this->json());
        self::assertSame([], $this->campaign($id)['sessions'] ?? null);
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $id = $this->campaignInASession();
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);

        foreach ([$id, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999'] as $campaignId) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions/current/end', $campaignId));

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
        }
    }

    private function campaignInASession(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'example-journal']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);

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
