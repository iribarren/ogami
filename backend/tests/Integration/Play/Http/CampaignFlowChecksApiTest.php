<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddCharacter;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\PickSceneType;
use App\Play\Application\StartSession;
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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Check steps over the Play API (ADR 0024), on the release "checks" (valid/v2-checks plus a "zero"
 * sheet field and the check "broken") and its Flow "tour". Every Scene Type has a check step
 * "roll-it" followed by the prompt "after": "open" (skill, the player sets the difficulty), "fixed"
 * (skill at 10: "fail" adds an alarm, "crit" ends the part), "tracked" (skill at the alarm + 9),
 * "plain" (luck, no difficulty), "wild" (skill at the alarm + 30, over the check's max), "unevaluable" (skill at the alarm / 0) and "broken" (a modifier that divides by zero). Vi joins before the
 * session and is in no scene; Ada joins during the scene (hands 4, so a skill modifier of 6).
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(FlowStepResponse::class)]
final class CampaignFlowChecksApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9801';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f81';

    private KernelBrowser $client;
    private CommandBus $commands;
    private RescriptableRandomNumberGenerator $random;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-14T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9411', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, ?string, ?int, bool}> the Scene Type, the check, the difficulty the step fixes and whether it fixes one
     */
    public static function steps(): iterable
    {
        yield 'the player sets the difficulty' => ['open', 'skill', null, false];
        yield 'a literal difficulty' => ['fixed', 'skill', 10, true];
        yield 'a difficulty over a Tracker' => ['tracked', 'skill', 9, true];
        yield 'a formula that cannot be evaluated' => ['unevaluable', 'skill', null, true];
        yield 'a check without a difficulty' => ['plain', 'luck', null, false];
    }

    #[Test]
    #[DataProvider('steps')]
    public function aCheckStepShowsItsCheckItsFixedDifficultyAndTheCharactersInTheScene(string $sceneType, string $check, ?int $difficulty, bool $difficultyFixed): void
    {
        $this->inScene($sceneType);

        $step = self::at($this->campaign(), 'flowRun', 'step');

        self::assertSame(
            ['roll-it', 'check', $check, $difficulty, $difficultyFixed, [['id' => 'char-ada', 'name' => 'Ada']], null, null],
            array_map(static fn (string $key): mixed => self::at($step, $key), ['key', 'kind', 'check', 'difficulty', 'difficultyFixed', 'characters', 'dice', 'oracle']),
        );
    }

    #[Test]
    public function aCharacterInTheSceneRollsTheCheckAndTheStepBranchesOnTheBand(): void
    {
        $this->inScene('fixed');
        // 1d10 shows 3: 3 + 6 = 9 is within the difficulty 10 - 1, a fail that adds an alarm.
        $this->random->script(3);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame('after', self::at($campaign, 'flowRun', 'step', 'key'));
        self::assertSame([1], array_values(array_map(static fn (mixed $tracker): mixed => self::at($tracker, 'value'), array_filter($this->list($campaign, 'trackers'), static fn (mixed $tracker): bool => 'alarm' === self::at($tracker, 'key')))));
        $entries = $this->journal();
        self::assertCount(1, $entries);
        self::assertSame(['check', 'roll-it'], [self::at($entries[0], 'kind'), self::at($entries[0], 'flowStep', 'key')]);
        self::assertSame(['char-ada', 'Ada', 'skill', 6, 10, 9, 'fail', 'Fail'], array_map(static fn (string $key): mixed => self::at($entries[0], 'content', $key), ['characterId', 'characterName', 'checkKey', 'modifier', 'difficulty', 'total', 'bandKey', 'bandLabel']));
    }

    #[Test]
    public function aBandWithAnOutcomeThatEndsThePartLeavesTheStepsBehind(): void
    {
        $this->inScene('fixed');
        // 9 + 6 = 15 is over 10 + 4: a crit, which ends the part before the prompt.
        $this->random->script(9);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertNull(self::at($this->json(), 'flowRun', 'step', 'key'));
    }

    #[Test]
    public function thePlayersDifficultyAndSituationalModifierApply(): void
    {
        $this->inScene('open');
        // 8 + 6 + 3 = 17 is a fail at the difficulty 20 (up to 19), a success at the default 13.
        $this->random->script(8);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'difficulty' => 20, 'situational' => 3]);

        self::assertResponseIsSuccessful();
        $content = self::at($this->journal()[0], 'content');
        self::assertSame([20, 3, 17, 'fail'], array_map(static fn (string $key): mixed => self::at($content, $key), ['difficulty', 'situational', 'total', 'bandKey']));
    }

    #[Test]
    public function theDefaultsApplyWhenTheDifficultyAndTheSituationalModifierAreOmittedOrNull(): void
    {
        $this->inScene('open');
        $this->random->script(8);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'difficulty' => null, 'situational' => null]);

        self::assertResponseIsSuccessful();
        $content = self::at($this->journal()[0], 'content');
        self::assertSame([13, 0, 14, 'success'], array_map(static fn (string $key): mixed => self::at($content, $key), ['difficulty', 'situational', 'total', 'bandKey']));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> the Scene Type, the body and the error
     */
    public static function refusedAnswers(): iterable
    {
        $ada = ['stepKey' => 'roll-it', 'characterId' => 'char-ada'];

        yield 'a Character outside the scene' => ['open', ['stepKey' => 'roll-it', 'characterId' => 'char-vi'], 'Check step "roll-it" rolls for a Character in the scene (in the session party outside one): Character "char-vi" is not.'];
        yield 'an unknown Character' => ['open', ['stepKey' => 'roll-it', 'characterId' => 'ghost'], 'Character step "roll-it" has no Character "ghost" in the campaign.'];
        yield 'no Character' => ['open', ['stepKey' => 'roll-it'], 'Step "roll-it" is a check step: it needs "characterId".'];
        yield 'a field of another kind' => ['open', $ada + ['text' => 'Sneaky'], 'Step "roll-it" is a check step: it takes no "text".'];
        yield 'a difficulty when the step fixes one' => ['fixed', $ada + ['difficulty' => 12], 'Step "roll-it" fixes the check difficulty at 10: it takes no other.'];
        yield 'a difficulty when the step fixes one over a Tracker' => ['tracked', $ada + ['difficulty' => 12], 'Step "roll-it" fixes the check difficulty at 9: it takes no other.'];
        yield 'a step difficulty over a Tracker that resolves over the max' => ['wild', $ada, 'Difficulty 30 is outside 9 to 29 of check "skill".'];
        yield 'a difficulty for a check without one' => ['plain', $ada + ['difficulty' => 5], 'Check "luck" declares no difficulty.'];
        yield 'a difficulty under the min' => ['open', $ada + ['difficulty' => 8], 'Difficulty 8 is outside 9 to 29 of check "skill".'];
        yield 'a difficulty over the max' => ['open', $ada + ['difficulty' => 30], 'Difficulty 30 is outside 9 to 29 of check "skill".'];
        yield 'a situational modifier over 100' => ['open', $ada + ['situational' => 101], 'The situational modifier must be between -100 and 100, 101 given.'];
        yield 'a check that cannot be evaluated' => ['broken', $ada, 'Check "broken" cannot be performed: The formula divides by zero.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedAnswers')]
    public function aRefusedAnswerIsUnprocessableAndRollsAndRecordsNothing(string $sceneType, array $body, string $error): void
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
    public function aStepThatIsNotTheCurrentOneIsAConflict(): void
    {
        $this->inScene('open');

        $this->answer(['stepKey' => 'after', 'characterId' => 'char-ada']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->journal());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'a difficulty that is a string' => [['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'difficulty' => '15']];
        yield 'a difficulty that is a float' => [['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'difficulty' => 15.5]];
        yield 'a situational modifier that is a string' => [['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'situational' => '2']];
        yield 'a situational modifier that is a list' => [['stepKey' => 'roll-it', 'characterId' => 'char-ada', 'situational' => [2]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedDifficultyOrSituationalModifierIsABadRequestAndRecordsNothing(array $body): void
    {
        $this->inScene('open');

        $this->answer($body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aCampaignSavedByAnotherRequestMeanwhileIsAConflictAndKeepsTheStep(): void
    {
        $this->inScene('open');
        $before = self::at($this->campaign(), 'flowRun');
        $this->random->script(8);

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
            $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, self::at($this->campaign(), 'flowRun'));
        self::assertSame([], $this->journal());
    }

    /**
     * The release "checks": valid/v2-checks with the Flow "tour" and a check step in every Scene Type.
     *
     * @return array<string, mixed>
     */
    private function release(): array
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}, checks: list<array<string, mixed>>} $base */
        $base = ReleaseViews::fixtureContent('valid/v2-checks');
        $content = ReleaseArrays::with($base, 'sheet.fields', [...$base['sheet']['fields'], ['key' => 'zero', 'label' => 'Zero', 'type' => 'number', 'default' => 0]]);
        $content = ReleaseArrays::with($content, 'checks', [
            ...$base['checks'],
            ['key' => 'broken', 'label' => 'Broken', 'dice' => '1d6', 'modifier' => '{hands} / {zero}', 'bands' => [['key' => 'only', 'label' => 'Only']]],
        ]);
        $step = static fn (string $check, array $changes = []): array => [
            ['key' => 'roll-it', 'kind' => 'check', 'title' => 'Roll it', 'check' => $check] + $changes,
            ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'],
        ];
        $fixed = ['difficulty' => 10, 'outcomes' => [
            ['band' => 'fail', 'effects' => [['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1]]],
            ['band' => 'crit', 'next' => 'end'],
        ]];
        $sceneTypes = ['open' => $step('skill'), 'fixed' => $step('skill', $fixed), 'tracked' => $step('skill', ['difficulty' => ['formula' => '{tracker:alarm}+9']]), 'plain' => $step('luck'), 'wild' => $step('skill', ['difficulty' => ['formula' => '{tracker:alarm}+30']]), 'unevaluable' => $step('skill', ['difficulty' => ['formula' => '{tracker:alarm} / 0']]), 'broken' => $step('broken')];
        $content = ReleaseArrays::with($content, 'sceneTypes', array_map(
            static fn (string $key, array $play): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['alarm'], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_keys($sceneTypes)]],
        ]]]);

        return $release;
    }

    /**
     * A guided campaign in a scene of this Scene Type, with Vi (added before the session, in no scene)
     * and Ada (added during the scene, so in it).
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The break-in', 'checks', 'tour'));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-vi', 'Vi', [], [], ['hands' => 7]));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], []));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answer(array $body): void
    {
        $this->client->request('POST', \sprintf('/api/campaigns/%s/flow-run/answer', self::CAMPAIGN), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
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

    /**
     * @return list<mixed>
     */
    private function list(mixed $data, string $key): array
    {
        $list = self::at($data, $key);
        self::assertIsArray($list);

        return array_values($list);
    }

    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = \is_array($data) && \array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $data;
    }
}
