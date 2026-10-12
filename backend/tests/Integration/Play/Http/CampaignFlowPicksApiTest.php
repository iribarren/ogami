<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddNpc;
use App\Play\Application\AddThread;
use App\Play\Application\Clock;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
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
