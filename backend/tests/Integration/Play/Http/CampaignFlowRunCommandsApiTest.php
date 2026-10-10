<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\EndFlowScene;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PickSceneType;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\GuidedReleases;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The FlowRun commands over the Play API, on the release "guided" (GuidedReleases): every endpoint
 * moves the FlowRun and answers with the refreshed campaign; every error is mapped and leaves the
 * campaign and the journal as they were. A campaign is brought to the state a case needs with
 * prepare(): "free" (no Flow), "waiting" (Flow "tour", no session yet), "pick" (Flow "tour" at the
 * scene pick), "step:<key>" (a Tour scene waiting at that step), "open" (the Tour scene in open
 * play), "paused", "draw" (Flow "draw", whose pick rolls a table) and "closing" (Flow "chain", the
 * first Solo scene ended: waiting at the mandatory step "wrap").
 */
#[CoversClass(FlowRunController::class)]
final class CampaignFlowRunCommandsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9501';
    private const string UNKNOWN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9599';
    private const string URL = '/api/campaigns/%s/flow-run/%s';

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

    /**
     * @return iterable<string, array{string, ?array<string, mixed>}> the endpoint and a body it takes
     */
    public static function endpointBodies(): iterable
    {
        foreach (self::endpoints() as $name => [$endpoint, $body]) {
            yield $name => [$endpoint, $body];
        }
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>, string}> the endpoint, a body it takes and the state it runs in
     */
    public static function endpoints(): iterable
    {
        yield 'answer' => ['answer', ['stepKey' => 'intro', 'text' => 'Ada, a fixer'], 'step:intro'];
        yield 'skip' => ['skip', ['stepKey' => 'intro'], 'step:intro'];
        yield 'pick' => ['pick', ['sceneType' => 'tour'], 'pick'];
        yield 'pick by oracle' => ['pick/roll', null, 'draw'];
        yield 'end scene' => ['end-scene', ['sceneNumber' => 1], 'open'];
        yield 'move on' => ['move-on', ['phase' => 'tour'], 'pick'];
        yield 'pause' => ['pause', null, 'pick'];
        yield 'resume' => ['resume', null, 'paused'];
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>, string, string, ?string, ?string, ?string}> the endpoint, its body, its state, then the FlowRun status, part and step key and the Scene Type it ends up with
     */
    public static function happyPaths(): iterable
    {
        yield 'answer moves on to the next step' => ['answer', ['stepKey' => 'intro', 'text' => 'Ada, a fixer'], 'step:intro', 'active', 'setup', 'dice', 'Tour'];
        yield 'skip moves on to the next step' => ['skip', ['stepKey' => 'intro'], 'step:intro', 'active', 'setup', 'dice', 'Tour'];
        yield 'pick starts the scene at its first step' => ['pick', ['sceneType' => 'tour'], 'pick', 'active', 'setup', 'intro', 'Tour'];
        yield 'pick by oracle starts the scene of the entry' => ['pick/roll', null, 'draw', 'active', 'setup', 'intro', 'Tour'];
        yield 'end scene starts the next scene pick' => ['end-scene', ['sceneNumber' => 1], 'open', 'active', null, null, null];
        yield 'move on ends the phase at the scene pick' => ['move-on', ['phase' => 'tour'], 'pick', 'completed', null, null, null];
        yield 'pause pauses the guidance' => ['pause', null, 'pick', 'paused', null, null, null];
        yield 'resume resumes the guidance' => ['resume', null, 'paused', 'active', null, null, null];
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('happyPaths')]
    public function aCommandMovesTheFlowRunAndAnswersWithTheRefreshedCampaign(string $endpoint, ?array $body, string $state, string $status, ?string $part, ?string $stepKey, ?string $sceneType): void
    {
        $this->prepare($state);

        $this->post($endpoint, $body);

        self::assertResponseStatusCodeSame(200);
        $campaign = $this->json();
        self::assertSame(self::CAMPAIGN, $campaign['id']);
        self::assertSame(
            [$status, $part, $stepKey, $sceneType],
            [self::at($campaign, 'flowRun', 'status'), self::at($campaign, 'flowRun', 'progress', 'part'), self::at($campaign, 'flowRun', 'step', 'key'), self::at($campaign, 'flowRun', 'progress', 'sceneType')],
        );
        self::assertEquals($campaign, $this->campaign());
    }

    #[Test]
    public function anAnswerRecordsItsEntryInTheJournalUnderItsStep(): void
    {
        $this->prepare('step:intro');

        $this->post('answer', ['stepKey' => 'intro', 'text' => 'Ada, a fixer']);

        self::assertResponseStatusCodeSame(200);
        $entries = $this->journal();
        self::assertCount(1, $entries);
        self::assertSame(['note', 'Ada, a fixer', 'intro'], [self::at($entries[0], 'kind'), self::at($entries[0], 'content', 'text'), self::at($entries[0], 'flowStep', 'key')]);
    }

    #[Test]
    public function aMoveOnInASceneLetsTheSceneGoOnToItsClosing(): void
    {
        $this->prepare('step:intro');

        $this->post('move-on', ['phase' => 'tour']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['active', 'intro'], [self::at($this->json(), 'flowRun', 'status'), self::at($this->json(), 'flowRun', 'step', 'key')]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|string}> a body the endpoint refuses as malformed
     */
    public static function malformedBodies(): iterable
    {
        yield 'answer: not JSON' => ['answer', '{oops'];
        yield 'answer: not an object' => ['answer', '"intro"'];
        yield 'answer: no step' => ['answer', ['text' => 'Ada']];
        yield 'answer: step not a string' => ['answer', ['stepKey' => 3]];
        yield 'answer: text not a string' => ['answer', ['stepKey' => 'intro', 'text' => 3]];
        yield 'answer: option not a string' => ['answer', ['stepKey' => 'fork', 'optionKey' => ['left']]];
        yield 'answer: likelihood not a string' => ['answer', ['stepKey' => 'ask', 'likelihood' => 3]];
        yield 'answer: chaos factor not an integer' => ['answer', ['stepKey' => 'ask', 'likelihood' => 'even', 'chaosFactor' => '5']];
        yield 'skip: not JSON' => ['skip', '{oops'];
        yield 'skip: no step' => ['skip', []];
        yield 'skip: step not a string' => ['skip', ['stepKey' => 3]];
        yield 'pick: not JSON' => ['pick', '{oops'];
        yield 'pick: no Scene Type' => ['pick', []];
        yield 'pick: Scene Type not a string' => ['pick', ['sceneType' => ['tour']]];
        yield 'end scene: not JSON' => ['end-scene', '{oops'];
        yield 'end scene: no scene number' => ['end-scene', []];
        yield 'end scene: scene number a string' => ['end-scene', ['sceneNumber' => '1']];
        yield 'end scene: scene number a fraction' => ['end-scene', ['sceneNumber' => 1.5]];
        yield 'move on: not JSON' => ['move-on', '{oops'];
        yield 'move on: no phase' => ['move-on', []];
        yield 'move on: phase not a string' => ['move-on', ['phase' => 1]];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndChangesNothing(string $endpoint, array|string $body): void
    {
        $this->prepare('step:intro');
        $before = $this->campaign();

        \is_string($body)
            ? $this->client->request('POST', \sprintf(self::URL, self::CAMPAIGN, $endpoint), server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            : $this->post($endpoint, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('Send a JSON object', $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    /**
     * @return iterable<string, array{string}> the endpoints that take a body
     */
    public static function endpointsWithABody(): iterable
    {
        foreach (['answer', 'skip', 'pick', 'end-scene', 'move-on'] as $endpoint) {
            yield $endpoint => [$endpoint];
        }
    }

    #[Test]
    #[DataProvider('endpointsWithABody')]
    public function aBodyThatIsNotJsonIsUnsupported(string $endpoint): void
    {
        $this->prepare('step:intro');

        $this->client->request('POST', \sprintf(self::URL, self::CAMPAIGN, $endpoint), server: ['CONTENT_TYPE' => 'text/plain'], content: 'stepKey=intro');

        self::assertResponseStatusCodeSame(415);
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('endpointBodies')]
    public function aCampaignThatDoesNotExistIsNotFound(string $endpoint, ?array $body): void
    {
        foreach ([self::UNKNOWN, 'not-an-id'] as $id) {
            $this->client->jsonRequest('POST', \sprintf(self::URL, $id, $endpoint), $body ?? []);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString($id, $this->error());
        }
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function aCampaignOfAnotherPlayerIsNotFound(string $endpoint, ?array $body, string $state): void
    {
        $this->prepare($state);
        $before = $this->campaign();
        $this->client->request('POST', '/api/auth/logout');
        $other = self::getContainer()->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($other, 'grace@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'grace@example.com', 'password' => self::PASSWORD]);

        $this->post($endpoint, $body);

        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertSame($before, $this->campaign());
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('endpointBodies')]
    public function aCampaignPlayedFreelyHasNoFlowRunToCommand(string $endpoint, ?array $body): void
    {
        $this->prepare('free');

        $this->post($endpoint, $body);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('The campaign plays freely: it follows no Flow.', $this->error());
        self::assertNull($this->campaign()['flowRun']);
        self::assertSame([], $this->journal());
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function aCampaignSavedByAnotherRequestMeanwhileIsAConflictAndKeepsTheFlowRun(string $endpoint, ?array $body, string $state): void
    {
        $this->prepare($state);
        $before = $this->campaign()['flowRun'];

        // Another request saves the campaign after this one loaded it, right before it flushes.
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
            $this->post($endpoint, $body);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(\sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN), $this->error());
        self::assertSame($before, $this->campaign()['flowRun']);
        self::assertSame([], $this->journal());
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>, string, string}> the endpoint, its body, the state it cannot run in and the error
     */
    public static function conflicts(): iterable
    {
        yield 'answer: another step' => ['answer', ['stepKey' => 'dice'], 'step:intro', 'The FlowRun is at step "intro", not at step "dice".'];
        yield 'answer: at the scene pick' => ['answer', ['stepKey' => 'intro', 'text' => 'Ada'], 'pick', 'The FlowRun is at no step, not at step "intro".'];
        yield 'answer: paused' => ['answer', ['stepKey' => 'intro', 'text' => 'Ada'], 'paused', 'Guidance is paused: resume it first.'];
        yield 'skip: another step' => ['skip', ['stepKey' => 'dice'], 'step:intro', 'The FlowRun is at step "intro", not at step "dice".'];
        yield 'skip: a mandatory step' => ['skip', ['stepKey' => 'wrap'], 'closing', 'Step "wrap" is mandatory: complete it.'];
        yield 'skip: paused' => ['skip', ['stepKey' => 'intro'], 'paused-in-scene', 'Guidance is paused: resume it first.'];
        yield 'pick: in a scene' => ['pick', ['sceneType' => 'tour'], 'step:intro', 'The FlowRun is at'];
        yield 'pick: a Scene Type the pick does not offer' => ['pick', ['sceneType' => 'solo'], 'pick', 'The scene pick does not offer Scene Type "solo".'];
        yield 'pick: a Scene Type the release does not have' => ['pick', ['sceneType' => 'nope'], 'pick', 'The scene pick does not offer Scene Type "nope".'];
        yield 'pick: a pick that rolls a table' => ['pick', ['sceneType' => 'tour'], 'draw', 'This scene pick rolls on table "scene-kinds".'];
        yield 'pick: no session under way' => ['pick', ['sceneType' => 'tour'], 'waiting', 'Start a session before starting a scene.'];
        yield 'pick: paused' => ['pick', ['sceneType' => 'tour'], 'paused', 'Guidance is paused: resume it first.'];
        yield 'pick by oracle: a pick by hand' => ['pick/roll', null, 'pick', 'This scene pick does not roll on a table.'];
        yield 'pick by oracle: in a scene' => ['pick/roll', null, 'step:intro', 'The FlowRun is at'];
        yield 'pick by oracle: no session under way' => ['pick/roll', null, 'waiting-draw', 'Start a session before starting a scene.'];
        yield 'pick by oracle: paused' => ['pick/roll', null, 'paused', 'Guidance is paused: resume it first.'];
        yield 'end scene: another scene' => ['end-scene', ['sceneNumber' => 2], 'open', 'The FlowRun is at'];
        yield 'end scene: not in open play' => ['end-scene', ['sceneNumber' => 1], 'step:intro', 'The FlowRun is at'];
        yield 'end scene: at the scene pick' => ['end-scene', ['sceneNumber' => 1], 'pick', 'The FlowRun is at'];
        yield 'end scene: paused' => ['end-scene', ['sceneNumber' => 1], 'paused-in-scene', 'Guidance is paused: resume it first.'];
        yield 'move on: another phase' => ['move-on', ['phase' => 'other'], 'step:intro', 'The FlowRun is at'];
        yield 'move on: a phase that plays once' => ['move-on', ['phase' => 'draw'], 'draw', 'Phase "draw" plays once: it ends after its scenes, not by moving on.'];
        yield 'move on: paused' => ['move-on', ['phase' => 'tour'], 'paused', 'Guidance is paused: resume it first.'];
        yield 'pause: already paused' => ['pause', null, 'paused', 'Guidance is already paused.'];
        yield 'resume: not paused' => ['resume', null, 'pick', 'Guidance is not paused.'];
    }

    /**
     * @param ?array<string, mixed> $body
     * @param non-empty-string      $message
     */
    #[Test]
    #[DataProvider('conflicts')]
    public function aFlowRunInAnotherStateIsAConflictAndChangesNothing(string $endpoint, ?array $body, string $state, string $message): void
    {
        $this->prepare($state);
        $before = $this->campaign();

        $this->post($endpoint, $body);

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith($message, $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aSessionThatHoldsTheMostScenesItCanRefusesAnotherOne(): void
    {
        $this->prepare('pick');
        for ($scene = 1; $scene <= 200; ++$scene) {
            $this->dispatch(new StartScene(self::CAMPAIGN, $this->userId, 'Scene '.$scene));
        }

        $this->post('pick', ['sceneType' => 'tour']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('200', $this->error());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}> an answer body, the state it is given in and the error
     */
    public static function unprocessableAnswers(): iterable
    {
        yield 'a prompt without text' => [['stepKey' => 'intro'], 'step:intro', 'Step "intro" is a prompt step: it needs a "text".'];
        yield 'a prompt with a blank text' => [['stepKey' => 'intro', 'text' => '  '], 'step:intro', 'Step "intro" needs an answer.'];
        yield 'a prompt with a field of another kind' => [['stepKey' => 'intro', 'text' => 'Ada', 'optionKey' => 'left'], 'step:intro', 'Step "intro" is a prompt step: it takes no "optionKey".'];
        yield 'a prompt with a text that is too long' => [['stepKey' => 'intro', 'text' => 'x'.str_repeat('y', NoteContent::MAX_LENGTH)], 'step:intro', 'A note must be at most 10000 characters, got 10001.'];
        yield 'a roll with a text' => [['stepKey' => 'dice', 'text' => 'Ada'], 'step:dice', 'Step "dice" is a roll step: it takes no "text".'];
        yield 'a choice without an option' => [['stepKey' => 'fork'], 'step:fork', 'Step "fork" is a choice step: it needs a "optionKey".'];
        yield 'a choice with an unknown option' => [['stepKey' => 'fork', 'optionKey' => 'up'], 'step:fork', 'Choice step "fork" has no option "up".'];
        yield 'an oracle without a likelihood' => [['stepKey' => 'ask'], 'step:ask', 'Step "ask" is a oracle step: it needs a "likelihood".'];
        yield 'an oracle with an unknown likelihood' => [['stepKey' => 'ask', 'likelihood' => 'bogus'], 'step:ask', 'There is no likelihood level "bogus"; the levels are "unlikely", "even".'];
        yield 'an oracle with a chaos factor out of range' => [['stepKey' => 'ask', 'likelihood' => 'even', 'chaosFactor' => 99], 'step:ask', 'The chaos factor is between 1 and 9, 99 given.'];
        yield 'an oracle that fixes the likelihood, given another' => [['stepKey' => 'ask-even', 'likelihood' => 'unlikely'], 'step:ask-even', 'Step "ask-even" asks at likelihood "even": it takes no other.'];
    }

    /**
     * @param array<string, mixed> $body
     * @param non-empty-string     $message
     */
    #[Test]
    #[DataProvider('unprocessableAnswers')]
    public function anAnswerTheStepCannotTakeIsUnprocessableAndRecordsNothing(array $body, string $state, string $message): void
    {
        $this->prepare($state);
        $before = $this->campaign();

        $this->post('answer', $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith($message, $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aChaosFactorForAnOracleThatTakesItFromATrackerIsUnprocessable(): void
    {
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9302', GuidedReleases::content(chaosFromTracker: true) + ['sheet' => []], false));
        $this->prepare('step:ask');
        $before = $this->campaign();

        $this->post('answer', ['stepKey' => 'ask', 'likelihood' => 'even', 'chaosFactor' => 4]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('chaos', $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    /**
     * @param ?array<string, mixed> $body
     */
    private function post(string $endpoint, ?array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf(self::URL, self::CAMPAIGN, $endpoint), $body ?? []);
    }

    private function prepare(string $state): void
    {
        $flow = match (true) {
            'free' === $state => null,
            \in_array($state, ['draw', 'waiting-draw'], true) => 'draw',
            'closing' === $state => 'chain',
            default => 'tour',
        };
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The tour', 'guided', $flow));
        if (\in_array($state, ['free', 'waiting', 'waiting-draw'], true)) {
            return;
        }

        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        if (str_starts_with($state, 'step:') || \in_array($state, ['open', 'paused-in-scene'], true)) {
            $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, 'tour'));
            $until = 'open' === $state ? null : ('paused-in-scene' === $state ? 'intro' : substr($state, 5));
            while (($step = $this->currentStepKey()) !== $until && null !== $step) {
                $this->dispatch(new SkipFlowStep(self::CAMPAIGN, $this->userId, $step));
            }
        }

        if ('closing' === $state) {
            $this->dispatch(new EndFlowScene(self::CAMPAIGN, $this->userId, 1));
        }

        if (\in_array($state, ['paused', 'paused-in-scene'], true)) {
            $this->dispatch(new PauseGuidance(self::CAMPAIGN, $this->userId));
        }
    }

    private function currentStepKey(): ?string
    {
        $flowRun = $this->campaign()['flowRun'];
        $key = \is_array($flowRun) && \is_array($flowRun['step']) ? $flowRun['step']['key'] : null;

        return \is_string($key) ? $key : null;
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
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
