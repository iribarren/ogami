<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddCharacter;
use App\Play\Application\Clock;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\CreateCampaign;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\PickSceneType;
use App\Play\Application\StartSession;
use App\Play\Application\SwitchSceneType;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Play\Infrastructure\Http\FlowStepResponse;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Check sets and tables by Scene Type over the Play API (ADR 0042), on the release "sets" with the
 * pool checks "grit" and "nerve" (3d6, success on 5+, bands miss, weak up to 1 and strong, both
 * pushable at the cost of 1 stress) and "steady" (the same bands, no push). Every Scene Type has a
 * step "roll-it" followed by the prompt "after": "duo" offers the set grit and nerve, "single" grit
 * alone, "wide" the set grit and steady; "town" and "wild" roll the table "trouble" (the table "any",
 * and "wild" in a Scene of the Scene Type "wild"). Ada is in the scene.
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(FlowStepResponse::class)]
#[CoversClass(CompleteFlowStepHandler::class)]
final class CampaignFlowCheckSetsAndTablesApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9861';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f83';

    private KernelBrowser $client;
    private CommandBus $commands;
    private RescriptableRandomNumberGenerator $random;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-26T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9461', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>}> the Scene Type, the single check and the set the step shows
     */
    public static function steps(): iterable
    {
        yield 'a check set' => ['duo', null, ['grit', 'nerve']];
        yield 'a single check' => ['single', 'grit', []];
    }

    /**
     * @param list<string> $checks
     */
    #[Test]
    #[DataProvider('steps')]
    public function aCheckStepShowsItsSingleCheckOrItsSet(string $sceneType, ?string $check, array $checks): void
    {
        $this->inScene($sceneType);

        $step = self::at($this->campaign(), 'flowRun', 'step');

        self::assertSame([$check, $checks], [self::at($step, 'check'), self::at($step, 'checks')]);
    }

    #[Test]
    public function aStepOfAnotherKindShowsNoChecks(): void
    {
        $this->inScene('town');

        self::assertSame([], self::at($this->campaign(), 'flowRun', 'step', 'checks'));
    }

    #[Test]
    public function theCheckOfTheSetThePlayerPicksIsTheOneRolledAndRecorded(): void
    {
        $this->inScene('duo');
        $this->random->script(5, 6, 5);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'checkKey' => 'nerve']);

        self::assertResponseIsSuccessful();
        self::assertSame('after', self::at($this->json(), 'flowRun', 'step', 'key'));
        $entries = $this->journal();
        self::assertSame([['check', 'nerve', 'strong']], array_map(static fn (array $entry): array => [self::at($entry, 'kind'), self::at($entry, 'content', 'checkKey'), self::at($entry, 'content', 'bandKey')], $entries));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> the Scene Type, the body and the error
     */
    public static function refusedKeys(): iterable
    {
        $ada = ['stepKey' => 'roll-it', 'characterId' => 'char-ada'];

        yield 'none for a set' => ['duo', $ada, 'Step "roll-it" is a check step: it needs "checkKey".'];
        yield 'one outside the set' => ['duo', $ada + ['checkKey' => 'steady'], 'Check step "roll-it" has no check "steady" in its set.'];
        yield 'an unknown one' => ['duo', $ada + ['checkKey' => 'ghost'], 'Check step "roll-it" has no check "ghost" in its set.'];
        yield 'one for a single check' => ['single', $ada + ['checkKey' => 'grit'], 'Step "roll-it" is a check step: it takes no "checkKey".'];
        yield 'one for a step of another kind' => ['town', ['stepKey' => 'roll-it', 'checkKey' => 'grit'], 'Step "roll-it" is a table step: it takes no "checkKey".'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedKeys')]
    public function aMissingUnknownOrUnexpectedCheckKeyIsUnprocessableAndRollsAndRecordsNothing(string $sceneType, array $body, string $error): void
    {
        $this->inScene($sceneType);
        $before = $this->campaign();

        $this->answer($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aCheckKeyThatIsNoStringIsABadRequestAndRecordsNothing(): void
    {
        $this->inScene('duo');

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'checkKey' => 5]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aPushableRollOfASetPendsAndKeepAndPushResolveItOnThePickedCheck(): void
    {
        $this->inScene('duo');
        $this->random->script(1, 5, 2);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'checkKey' => 'nerve']);
        self::assertResponseIsSuccessful();
        self::assertSame('roll-it', self::at($this->campaign(), 'flowRun', 'step', 'key'));
        self::assertSame('char-ada', self::at($this->campaign(), 'flowRun', 'step', 'pending', 'characterId'));

        $this->random->script(6, 6);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/push', self::CAMPAIGN), ['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame(['after', null], [self::at($this->json(), 'flowRun', 'step', 'key'), self::at($this->json(), 'flowRun', 'step', 'pending')]);
        self::assertSame('nerve', self::at($this->journal()[0], 'content', 'checkKey'));
    }

    #[Test]
    public function keepResolvesAPendingRollOfASet(): void
    {
        $this->inScene('duo');
        $this->random->script(1, 5, 2);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'checkKey' => 'grit']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/keep', self::CAMPAIGN), ['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame('after', self::at($this->json(), 'flowRun', 'step', 'key'));
    }

    #[Test]
    public function aSetRollOfACheckWithoutAPushCompletesTheStepAtOnce(): void
    {
        $this->inScene('wide');
        $this->random->script(1, 5, 2);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'checkKey' => 'steady']);

        self::assertResponseIsSuccessful();
        self::assertSame(['after', null], [self::at($this->json(), 'flowRun', 'step', 'key'), self::at($this->json(), 'flowRun', 'step', 'pending')]);
    }

    /**
     * @return iterable<string, array{string, string}> the Scene Type and the table the step rolls in it
     */
    public static function tables(): iterable
    {
        yield 'a Scene Type the map leaves out' => ['town', 'any'];
        yield 'a mapped Scene Type' => ['wild', 'wild'];
    }

    #[Test]
    #[DataProvider('tables')]
    public function aTableStepShowsAndRollsTheTableOfTheSceneType(string $sceneType, string $table): void
    {
        $this->inScene($sceneType);
        $this->random->script(1);

        self::assertSame($table, self::at($this->campaign(), 'flowRun', 'step', 'table'));
        $this->answer(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame([['oracle-table', $table]], array_map(static fn (array $entry): array => [self::at($entry, 'kind'), self::at($entry, 'content', 'oracleKey')], $this->journal()));
    }

    #[Test]
    public function switchingTheSceneTypeChangesTheTableOfLaterRolls(): void
    {
        $this->inScene('town');
        self::assertSame('any', self::at($this->campaign(), 'flowRun', 'step', 'table'));

        $this->dispatch(new SwitchSceneType(self::CAMPAIGN, $this->userId, 'wild'));

        self::assertSame('wild', self::at($this->campaign(), 'flowRun', 'step', 'table'));
    }

    /**
     * The release "sets": valid/v2-dice-pools with the checks, tables and Scene Types above.
     *
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $bands = [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 1], ['key' => 'strong', 'label' => 'Strong']];
        $pool = ['count' => 3, 'sides' => 6, 'successAt' => 5];
        $push = ['label' => 'Take stress', 'effects' => [['kind' => 'sheet', 'field' => 'stress', 'op' => 'add', 'value' => 1]]];
        $table = static fn (string $key): array => ['key' => $key, 'name' => ucfirst($key), 'entries' => [['text' => 'A '.$key.' rumour'], ['text' => 'A '.$key.' stranger']]];
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-dice-pools'), 'gameSystem.key', 'sets');
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [['key' => 'stress', 'label' => 'Stress', 'type' => 'number', 'min' => 0, 'max' => 10]]]);
        $content = ReleaseArrays::with($content, 'oracles', ['tables' => [$table('any'), $table('wild')], 'likelihood' => []]);
        $content = ReleaseArrays::with($content, 'checks', [
            ['key' => 'grit', 'label' => 'Grit', 'pool' => $pool, 'bands' => $bands, 'push' => $push],
            ['key' => 'nerve', 'label' => 'Nerve', 'pool' => $pool, 'bands' => $bands, 'push' => $push],
            ['key' => 'steady', 'label' => 'Steady', 'pool' => $pool, 'bands' => $bands],
        ]);
        $after = ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'];
        $check = static fn (array $keys): array => [['key' => 'roll-it', 'kind' => 'check', 'title' => 'Roll it'] + $keys, $after];
        $trouble = [['key' => 'roll-it', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any', 'tableBySceneType' => ['wild' => 'wild']], $after];
        $sceneTypes = [
            'duo' => $check(['checks' => ['grit', 'nerve']]),
            'single' => $check(['check' => 'grit']),
            'wide' => $check(['checks' => ['grit', 'steady']]),
            'town' => $trouble,
            'wild' => $trouble,
        ];
        $content = ReleaseArrays::with($content, 'sceneTypes', array_map(
            static fn (string $key, array $play): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_keys($sceneTypes)]],
        ]]]);

        return $release;
    }

    /**
     * A guided campaign in a scene of this Scene Type, with Ada in it.
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The climb', 'sets', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], []));
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
        $error = self::at($this->json(), 'error');

        return \is_string($error) ? $error : '';
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
