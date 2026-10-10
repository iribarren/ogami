<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CreateCampaign;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PickSceneType;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignResponse;
use App\Play\Infrastructure\Http\ChoiceContentResponse;
use App\Play\Infrastructure\Http\FlowRunProgressResponse;
use App\Play\Infrastructure\Http\FlowRunResponse;
use App\Play\Infrastructure\Http\FlowStepOptionResponse;
use App\Play\Infrastructure\Http\FlowStepResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Play\Infrastructure\Http\JournalEntryResponse;
use App\Play\Infrastructure\Http\JournalFlowStepResponse;
use App\Play\Infrastructure\Http\ScenePickResponse;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\GuidedReleases;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The FlowRun of a guided campaign over the Play API, on the release "guided" (GuidedReleases) and
 * its Flow "tour": the scene pick, a step of each kind, a paused FlowRun, and the journal entries a
 * step records. The FlowRun is driven through the command bus; its HTTP commands come with T10c2.
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(JournalController::class)]
#[CoversClass(CampaignResponse::class)]
#[CoversClass(FlowRunResponse::class)]
#[CoversClass(FlowRunProgressResponse::class)]
#[CoversClass(FlowStepResponse::class)]
#[CoversClass(FlowStepOptionResponse::class)]
#[CoversClass(ScenePickResponse::class)]
#[CoversClass(JournalEntryResponse::class)]
#[CoversClass(ChoiceContentResponse::class)]
#[CoversClass(JournalFlowStepResponse::class)]
final class CampaignFlowRunApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9401';
    private const string ENTRY_1 = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9411';
    private const string ENTRY_2 = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9412';

    private const array SCENE_PICK = [
        'rule' => 'player',
        'cards' => [
            ['key' => 'tour', 'name' => 'Tour', 'purpose' => 'A tour scene.'],
            ['key' => 'quiet', 'name' => 'Quiet', 'purpose' => 'A quiet scene.'],
        ],
        'table' => null,
        'forced' => false,
    ];

    private const array NO_PROGRESS = ['act' => null, 'phase' => 'Tour', 'sceneType' => null, 'part' => null, 'stepNumber' => null, 'stepCount' => null];

    private KernelBrowser $client;
    private CommandBus $commands;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        // One container across requests, so the fixed clock is the one the requests use.
        $container->set(Clock::class, new FixedClock('2026-10-10T09:00:00+00:00'));
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9301', GuidedReleases::content() + ['sheet' => []], false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aCampaignPlayedFreelyHasNoFlowRun(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'Free', 'guided', null));

        self::assertNull($this->campaign()['flowRun']);
    }

    #[Test]
    public function aGuidedCampaignWaitsForTheFirstSession(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The tour', 'guided', 'tour'));

        self::assertSame($this->atTheScenePick(waitsForSession: true), $this->campaign()['flowRun']);
    }

    #[Test]
    public function aGuidedCampaignWaitsAtTheScenePickWithOneCardPerSceneType(): void
    {
        $this->startGuidedSession();

        self::assertSame($this->atTheScenePick(waitsForSession: false), $this->campaign()['flowRun']);
    }

    /**
     * @return iterable<string, array{string, int, string, array<mixed>}>
     */
    public static function currentSteps(): iterable
    {
        $step = static fn (string $key, string $kind, string $title, array $fields = []): array => array_replace(['key' => $key, 'kind' => $kind, 'title' => $title, 'prompt' => null, 'tip' => null, 'mandatory' => false, 'oracle' => null, 'likelihood' => null, 'table' => null, 'dice' => null, 'options' => []], $fields);

        yield 'a prompt' => ['intro', 1, 'Setup: Push your luck', $step('intro', 'prompt', 'Who walks in?', ['prompt' => 'Describe the first person.'])];
        yield 'a roll' => ['dice', 2, 'Setup: Read the omen', $step('dice', 'roll', 'Push your luck', ['dice' => '1d6'])];
        yield 'a table' => ['omen', 3, 'Setup: Is the gate open?', $step('omen', 'table', 'Read the omen', ['table' => 'omens'])];
        yield 'an oracle the player picks the likelihood of' => ['ask', 4, 'Setup: Is it guarded?', $step('ask', 'oracle', 'Is the gate open?', ['oracle' => 'fate'])];
        yield 'an oracle at a fixed likelihood' => ['ask-even', 5, 'Setup: Which way?', $step('ask-even', 'oracle', 'Is it guarded?', ['oracle' => 'fate', 'likelihood' => 'even'])];
        yield 'a choice' => ['fork', 6, 'Open play', $step('fork', 'choice', 'Which way?', ['options' => [['key' => 'left', 'label' => 'Go left'], ['key' => 'right', 'label' => 'Go right']]])];
    }

    /**
     * @param array<string, mixed> $expectedStep
     */
    #[Test]
    #[DataProvider('currentSteps')]
    public function aGuidedCampaignWaitsAtAStepOfEveryKindWithTheFieldsOfItsKind(string $stepKey, int $stepNumber, string $next, array $expectedStep): void
    {
        $this->startGuidedSession();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'tour'));
        $this->skipUntil($stepKey);

        self::assertSame([
            'status' => 'active',
            'waitsForSession' => false,
            'progress' => ['act' => null, 'phase' => 'Tour', 'sceneType' => 'Tour', 'part' => 'setup', 'stepNumber' => $stepNumber, 'stepCount' => 6],
            'step' => $expectedStep,
            'pick' => null,
            'canMoveOn' => true,
            'next' => $next,
        ], $this->campaign()['flowRun']);
    }

    #[Test]
    public function aPausedFlowRunShowsItsStatus(): void
    {
        $this->startGuidedSession();
        $this->dispatch(new PauseGuidance(self::CAMPAIGN, $this->userId));

        self::assertSame(array_replace($this->atTheScenePick(waitsForSession: false), ['status' => 'paused', 'canMoveOn' => false]), $this->campaign()['flowRun']);
    }

    #[Test]
    public function theJournalEntriesAStepRecordedNameTheStepAndAChoiceMapsToItsContent(): void
    {
        $this->startGuidedSession();
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'tour'));
        $this->dispatch(new CompleteFlowStep(self::CAMPAIGN, $this->userId, self::ENTRY_1, 'intro', 'Ada, a fixer', null, null, null));
        $this->skipUntil('fork');
        $this->dispatch(new CompleteFlowStep(self::CAMPAIGN, $this->userId, self::ENTRY_2, 'fork', null, 'right', null, null));
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', self::CAMPAIGN), ['text' => 'Written by hand']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', self::CAMPAIGN));

        self::assertResponseIsSuccessful();
        $entries = $this->json();
        $handWritten = $entries[2] ?? null;
        self::assertIsArray($handWritten);
        self::assertIsString($handWritten['id'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $handWritten['id']);
        $entries[2] = ['id' => 'hand-written'] + $handWritten;
        self::assertSame([
            ['id' => self::ENTRY_1, 'sessionNumber' => 1, 'sceneNumber' => 1, 'recordedAt' => '2026-10-10T09:00:00+00:00', 'kind' => 'note', 'content' => ['kind' => 'note', 'text' => 'Ada, a fixer'], 'flowStep' => ['key' => 'intro', 'title' => 'Who walks in?', 'prompt' => 'Describe the first person.']],
            ['id' => self::ENTRY_2, 'sessionNumber' => 1, 'sceneNumber' => 1, 'recordedAt' => '2026-10-10T09:00:00+00:00', 'kind' => 'choice', 'content' => ['kind' => 'choice', 'question' => 'Which way?', 'optionKey' => 'right', 'label' => 'Go right'], 'flowStep' => ['key' => 'fork', 'title' => 'Which way?', 'prompt' => null]],
            ['id' => 'hand-written', 'sessionNumber' => 1, 'sceneNumber' => 1, 'recordedAt' => '2026-10-10T09:00:00+00:00', 'kind' => 'note', 'content' => ['kind' => 'note', 'text' => 'Written by hand'], 'flowStep' => null],
        ], $entries);
    }

    /**
     * @return array<string, mixed> the FlowRun view at the scene pick of the Flow "tour"
     */
    private function atTheScenePick(bool $waitsForSession): array
    {
        return [
            'status' => 'active',
            'waitsForSession' => $waitsForSession,
            'progress' => self::NO_PROGRESS,
            'step' => null,
            'pick' => self::SCENE_PICK,
            'canMoveOn' => true,
            'next' => 'Next scene: choose a scene type',
        ];
    }

    private function startGuidedSession(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The tour', 'guided', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
    }

    private function skipUntil(string $stepKey): void
    {
        $current = $this->currentStepKey();
        while ($current !== $stepKey) {
            self::assertIsString($current, 'The FlowRun reached no step.');
            $this->dispatch(new SkipFlowStep(self::CAMPAIGN, $this->userId, $current));
            $current = $this->currentStepKey();
        }
    }

    private function currentStepKey(): mixed
    {
        $flowRun = $this->campaign()['flowRun'] ?? null;
        $step = \is_array($flowRun) ? ($flowRun['step'] ?? null) : null;

        return \is_array($step) ? ($step['key'] ?? null) : null;
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
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
