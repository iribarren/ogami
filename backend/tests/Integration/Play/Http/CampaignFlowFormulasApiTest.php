<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\PickSceneType;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Formulas in a Flow over the Play API (ADR 0023), on a release with the counters "heat" (3),
 * "zero" (0) and "alarm" (0 to 20): the Scene Type "boost" adds max(heat, 2) to the alarm,
 * "broken" bumps the heat and then divides it by zero, and "trap" opens with a condition step
 * whose band bound divides by zero. "Huge" holds a thousand: "big" adds a formula worth a billion
 * (valid, clamped to the alarm's range) and "overflow" one past it (a 422). An evaluation failure is a 422 that leaves the campaign as it
 * was, whatever the command had already done.
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(CampaignController::class)]
final class CampaignFlowFormulasApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9511';

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
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9311', $this->content(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aFormulaEffectAddsItsValueToTheTracker(): void
    {
        $this->inScene('boost');

        $this->client->jsonRequest('POST', $this->url('answer'), ['stepKey' => 'boost', 'text' => 'Go']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->trackerValue('alarm'), 'max(3, 2)');
    }

    #[Test]
    public function aFormulaEffectThatDividesByZeroIsA422AndChangesNothing(): void
    {
        $this->inScene('broken');
        $before = $this->campaign();

        $this->client->jsonRequest('POST', $this->url('answer'), ['stepKey' => 'broken', 'text' => 'Go']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('divides by zero', $this->error());
        self::assertSame($before, $this->campaign(), 'The heat bump before the failing effect is undone.');
        self::assertSame(3, $this->trackerValue('heat'));
    }

    #[Test]
    public function aBandBoundThatDividesByZeroIsA422AndNoSceneStarts(): void
    {
        $this->commands->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The formulas', 'formulas', 'formulas'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $before = $this->campaign();

        $this->client->jsonRequest('POST', $this->url('pick'), ['sceneType' => 'trap']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('divides by zero', $this->error());
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aHandSwitchToASceneTypeWhoseSetupDividesByZeroIsA422AndChangesNothing(): void
    {
        $this->inScene('boost');
        $before = $this->campaign();

        $this->client->jsonRequest('POST', '/api/campaigns/'.self::CAMPAIGN.'/scenes/current/scene-type', ['sceneType' => 'trap']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aSessionOpeningThatDividesByZeroIsA422AndNoSessionStarts(): void
    {
        $this->commands->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The formulas', 'formulas', 'opening'));
        $before = $this->campaign();

        $this->client->request('POST', '/api/campaigns/'.self::CAMPAIGN.'/sessions');

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aSessionClosingThatDividesByZeroIsA422AndTheSessionStaysOpen(): void
    {
        $this->commands->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The formulas', 'formulas', 'closing'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $before = $this->campaign();

        $this->client->request('POST', '/api/campaigns/'.self::CAMPAIGN.'/sessions/current/end');

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->campaign());
    }

    #[Test]
    public function aFormulaEffectWorthABillionIsClampedToTheTrackerRange(): void
    {
        $this->inScene('big');

        $this->client->jsonRequest('POST', $this->url('answer'), ['stepKey' => 'big', 'text' => 'Go']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(20, $this->trackerValue('alarm'), '1,000 * 1,000,000 is clamped to the alarm max');
    }

    #[Test]
    public function aFormulaEffectThatOverflowsIsA422AndChangesNothing(): void
    {
        $this->inScene('overflow');
        $before = $this->campaign();

        $this->client->jsonRequest('POST', $this->url('answer'), ['stepKey' => 'overflow', 'text' => 'Go']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cannot be evaluated', $this->error());
        self::assertSame($before, $this->campaign());
    }

    private function inScene(string $sceneType): void
    {
        $this->commands->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The formulas', 'formulas', 'formulas'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
    }

    private function url(string $endpoint): string
    {
        return \sprintf('/api/campaigns/%s/flow-run/%s', self::CAMPAIGN, $endpoint);
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function trackerValue(string $key): int
    {
        $trackers = $this->campaign()['trackers'];
        self::assertIsArray($trackers);
        foreach ($trackers as $tracker) {
            if (\is_array($tracker) && $key === $tracker['key']) {
                self::assertIsInt($tracker['value']);

                return $tracker['value'];
            }
        }

        self::fail('No Tracker '.$key);
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

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => ucfirst($key)] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $tracker = static fn (string $key, int $max, int $initial): array => ['key' => $key, 'name' => ucfirst($key), 'kind' => 'counter', 'min' => 0, 'max' => $max, 'initial' => $initial];
        $effect = static fn (string $key, int|array $value): array => ['kind' => 'tracker', 'tracker' => $key, 'op' => 'add', 'value' => $value];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'formulas', 'name' => 'Formulas'],
            'sheet' => [],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [$tracker('heat', 9, 3), $tracker('zero', 9, 0), $tracker('alarm', 20, 0), $tracker('huge', 1000, 1000)],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('boost', [$prompt('boost', ['effects' => [$effect('alarm', ['formula' => 'max({tracker:heat}, 2)'])]])]),
                $sceneType('broken', [$prompt('broken', ['effects' => [$effect('heat', 1), $effect('alarm', ['formula' => '{tracker:heat} / {tracker:zero}'])]])]),
                $sceneType('big', [$prompt('big', ['effects' => [$effect('alarm', ['formula' => '{tracker:huge} * 1000000'])]])]),
                $sceneType('overflow', [$prompt('overflow', ['effects' => [$effect('alarm', ['formula' => '{tracker:huge} * 1000000 * 1000'])]])]),
                $sceneType('trap', [
                    ['key' => 'trap', 'kind' => 'condition', 'title' => 'Trap', 'tracker' => 'heat', 'bands' => [['upTo' => ['formula' => '1 / {tracker:zero}'], 'next' => 'low'], ['next' => 'high']]],
                    $prompt('low'), $prompt('high'),
                ]),
            ],
            'flows' => [...array_map(static fn (string $hook): array => ['key' => $hook, 'name' => ucfirst($hook), 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat', 'zero', 'alarm', 'huge'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['boost', 'broken']], 'session'.ucfirst($hook) => [
                    ['key' => 'check', 'kind' => 'condition', 'title' => 'Check', 'tracker' => 'heat', 'bands' => [['upTo' => ['formula' => '1 / {tracker:zero}']], []]],
                ]],
            ]], ['opening', 'closing']), ['key' => 'formulas', 'name' => 'Formulas', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat', 'zero', 'alarm', 'huge'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['boost', 'broken', 'trap', 'big', 'overflow']]],
            ]]],
            'checks' => [],
        ];
    }
}
