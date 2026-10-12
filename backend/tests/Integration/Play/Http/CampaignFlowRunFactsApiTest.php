<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Application\StartSession;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\Http\FactSlotResponse;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Play\Infrastructure\Http\FlowStepResponse;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * fillFact Effects over the Play API, on the schema version 2 release "valid/v2-campaign-facts"
 * played by one Flow whose Scene Type "scene" has the steps city, prince and ambition: a completed
 * step fills its slots (shown in the campaign's facts), the step lists the slots it may fill, and a
 * generated fact id the campaign already holds is a 409 that changes nothing.
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(FlowStepResponse::class)]
#[CoversClass(FactSlotResponse::class)]
final class CampaignFlowRunFactsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9601';

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
        $content = ReleaseViews::fixtureContent('valid/v2-campaign-facts');
        $content['flows'] = [['key' => 'facts', 'name' => 'Facts', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]],
        ]]];
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9301', $content, false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aStepListsTheSlotsItMayFillAndCompletingItFillsThemWithTheirValues(): void
    {
        $this->startScene();

        self::assertSame([['key' => 'city', 'label' => 'City', 'type' => 'text', 'hint' => 'Where the chronicle begins.']], self::at($this->campaign(), 'flowRun', 'step', 'fills'));

        $this->answer('city', 'Prague');

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame([['key' => 'prince', 'label' => 'Prince', 'type' => 'npc', 'hint' => 'Who rules the city.']], self::at($campaign, 'flowRun', 'step', 'fills'));
        self::assertSame('Rule of Prague', self::at($campaign, 'flowRun', 'step', 'title'));
        self::assertSame([['city', 'Prague', 'Prague']], $this->factRows($campaign, 'slot', 'text', 'value'));
        self::assertSame($campaign, $this->campaign());

        $this->answer('prince', 'Vlad');

        self::assertSame([['city', 'Prague'], ['prince', 'Prince of Prague']], $this->factRows($this->json(), 'slot', 'value'));
    }

    #[Test]
    public function aGeneratedFactIdTheCampaignAlreadyHoldsIsAConflictAndChangesNothing(): void
    {
        $this->startScene();
        // One fact exists, so the slot fact the step adds would take the id this one holds.
        $this->seed(static function (Campaign $campaign, GameSystemSnapshot $release): void {
            $campaign->addFreeFact(GeneratedItemId::fact(self::CAMPAIGN, 1), 'Squatter', 'Text', [], $release);
        });
        $before = $this->campaign();

        $this->answer('city', 'Prague');

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Fact "%s" already exists.', GeneratedItemId::fact(self::CAMPAIGN, 1)->toString())], $this->json());
        self::assertSame($before, $this->campaign());
    }

    private function startScene(): void
    {
        // The Flow offers one Scene Type, so the session starts at its first step.
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'Chronicle', 'campaign-facts', 'facts'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
    }

    private function answer(string $stepKey, string $text): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/answer', self::CAMPAIGN), ['stepKey' => $stepKey, 'text' => $text]);
    }

    /**
     * @param callable(Campaign, GameSystemSnapshot): void $change
     */
    private function seed(callable $change): void
    {
        $campaigns = self::getContainer()->get(CampaignRepository::class);
        $campaign = $campaigns->ofId(CampaignId::fromString(self::CAMPAIGN)) ?? throw new \LogicException('No campaign.');
        $pinned = $campaign->pinnedRelease();
        $change($campaign, self::getContainer()->get(PublishedGameSystemReleases::class)->get($pinned->gameSystemKey(), $pinned->releaseVersion()));
        $campaigns->save($campaign);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<mixed> $campaign
     *
     * @return list<list<mixed>> the given fields of each fact
     */
    private function factRows(array $campaign, string ...$fields): array
    {
        $facts = $campaign['facts'] ?? null;
        self::assertIsArray($facts);

        return array_values(array_map(static fn (mixed $fact): array => array_map(static fn (string $field): mixed => \is_array($fact) ? ($fact[$field] ?? null) : null, array_values($fields)), $facts));
    }

    /**
     * Reads a value down nested arrays, null when a key is missing.
     *
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = \is_array($data) && \array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $data;
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
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
