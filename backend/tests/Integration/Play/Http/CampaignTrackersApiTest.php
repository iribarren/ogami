<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\JournalController;
use App\Play\Infrastructure\Http\TrackerLevelResponse;
use App\Play\Infrastructure\Http\TrackerResponse;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Campaign Trackers over the Play API, on the schema version 2 release "valid/v2-catalog": a clock
 * "alarm" (6), counters "edge" (0..3, levels), "chaos" (1..9, bound to the "fate" oracle) and
 * "heat" (-5..5, levels). The likelihood oracle "omen" takes its chaos factor from the request.
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(JournalController::class)]
#[CoversClass(TrackerResponse::class)]
#[CoversClass(TrackerLevelResponse::class)]
final class CampaignTrackersApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container across requests, so the doubles below are the ones the requests use.
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-09T09:00:00+00:00'));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
        $container->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', ReleaseViews::fixtureContent('valid/v2-catalog'), false));
    }

    #[Test]
    public function aNewCampaignShowsEveryTrackerAtItsStartingValue(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame([
            $this->tracker('alarm', 'Alarm', 'clock', 'At 6/6 security locks down: you run', 0, 6, 6, [], 0, null),
            $this->tracker('edge', 'Edge', 'counter', null, 0, 3, null, [['upTo' => 0, 'label' => 'No edge'], ['upTo' => null, 'label' => 'An edge']], 0, 'No edge'),
            $this->tracker('chaos', 'Chaos factor', 'counter', null, 1, 9, null, [], 5, null),
            $this->tracker('heat', 'Heat', 'counter', 'Grows between jobs', -5, 5, null, self::HEAT_LEVELS, -5, 'Cold'),
        ], $campaign['trackers'] ?? null);
        self::assertIsArray($campaign['likelihoodOracles'] ?? null);
        self::assertSame(['chaos', null], array_column($campaign['likelihoodOracles'], 'chaosTracker'));
    }

    /**
     * @return iterable<string, array{string, int, int, ?string}>
     */
    public static function handEdits(): iterable
    {
        yield 'counter in range' => ['heat', 2, 2, 'Warm'];
        yield 'counter above max' => ['heat', 12, 5, 'Hot'];
        yield 'counter below min' => ['heat', -40, -5, 'Cold'];
        yield 'clock past its segments' => ['alarm', 7, 6, null];
        yield 'clock below zero' => ['alarm', -1, 0, null];
    }

    #[Test]
    #[DataProvider('handEdits')]
    public function aSoloPlayerSetsATrackerByHandAndTheValueIsClamped(string $key, int $value, int $kept, ?string $levelLabel): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/%s', $id, $key), ['value' => $value]);

        self::assertResponseIsSuccessful();
        $tracker = $this->json();
        self::assertSame($key, $tracker['key'] ?? null);
        self::assertSame($kept, $tracker['value'] ?? null);
        self::assertArrayHasKey('levelLabel', $tracker);
        self::assertSame($levelLabel, $tracker['levelLabel']);

        $this->client->request('GET', '/api/campaigns/'.$id);
        $trackers = $this->json()['trackers'] ?? null;
        self::assertIsArray($trackers);
        self::assertSame($kept, array_column($trackers, 'value', 'key')[$key] ?? null);
    }

    #[Test]
    public function anUnknownTrackerIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/luck', $id), ['value' => 1]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Tracker "luck" not found.'], $this->json());
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([$bobs, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999', 'not-a-uuid'] as $id) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/heat', $id), ['value' => 1]);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not an object' => ['[3]'];
        yield 'no value' => ['{}'];
        yield 'a string value' => ['{"value": "3"}'];
        yield 'a decimal value' => ['{"value": 2.5}'];
        yield 'invalid JSON' => ['{"value":'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('PUT', \sprintf('/api/campaigns/%s/trackers/heat', $id), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with an integer "value", such as {"value": 3}.'], $this->json());
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('PUT', \sprintf('/api/campaigns/%s/trackers/heat', $id), ['value' => '3']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the value as JSON.'], $this->json());
    }

    #[Test]
    public function settingATrackerIsForSoloPlayersOnly(): void
    {
        $this->client->jsonRequest('PUT', '/api/campaigns/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999/trackers/heat', ['value' => 1]);
        self::assertResponseStatusCodeSame(401);

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            $this->client->jsonRequest('PUT', '/api/campaigns/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999/trackers/heat', ['value' => 1]);

            self::assertResponseStatusCodeSame(403);
            self::assertSame(['error' => 'Access denied.'], $this->json());
        }
    }

    #[Test]
    public function anOracleBoundToATrackerAsksWithTheTrackerValue(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->campaignInAScene();
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/chaos', $id), ['value' => 8]);
        $this->random->script(60);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), ['likelihood' => 'even']);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        // "50/50" targets 50; chaos 8 is 3 above neutral, at 5 per point: 65.
        self::assertSame([8, 65], [$content['chaosFactor'] ?? null, $content['effectiveTarget'] ?? null]);
    }

    #[Test]
    public function anOracleBoundToATrackerRefusesAChaosFactor(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->campaignInAScene();
        $this->random->script(60);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), ['likelihood' => 'even', 'chaosFactor' => 5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Likelihood oracle "fate" takes its chaos factor from tracker "chaos": send no chaos factor.'], $this->json());

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        self::assertSame([], $this->json());
    }

    #[Test]
    public function anUnboundOracleStillTakesTheRequestedChaosFactor(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->campaignInAScene();
        $this->random->script(2);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/omen', $id), ['likelihood' => 'even', 'chaosFactor' => 2]);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(2, $content['chaosFactor'] ?? null);
    }

    private const array HEAT_LEVELS = [
        ['upTo' => -1, 'label' => 'Cold'],
        ['upTo' => 2, 'label' => 'Warm'],
        ['upTo' => null, 'label' => 'Hot'],
    ];

    /**
     * @param list<array{upTo: ?int, label: string}> $levels
     *
     * @return array<string, mixed>
     */
    private function tracker(string $key, string $name, string $kind, ?string $hint, int $min, int $max, ?int $segments, array $levels, int $value, ?string $levelLabel): array
    {
        return ['key' => $key, 'name' => $name, 'kind' => $kind, 'hint' => $hint, 'min' => $min, 'max' => $max, 'segments' => $segments, 'levels' => $levels, 'value' => $value, 'levelLabel' => $levelLabel];
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'catalog']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function campaignInAScene(): string
    {
        $id = $this->createCampaign();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'The vault']);
        self::assertResponseStatusCodeSame(201);

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
