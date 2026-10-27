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
use App\Play\Application\ResolvePendingFlowCheckHandler;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Play\Infrastructure\Http\FlowStepPendingResponse;
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
 * A pushable check step roll pends until Keep or Push (ADR 0041), over the Play API, on the release
 * "pushing" with the sheet agility (3), zero (1), stress (0 to 10) and the conditions physical (hurt,
 * exhausted), the Tracker "dread" (a clock of 6) and the pool checks "grit" (3d6, success on 5+,
 * bands miss, weak up to 1, strong; push: stress +1 and a pick of physical), "steady" (no push) and
 * "cursed" (its push cost divides by zero). Every Scene Type has the check step "roll-it", adding 1
 * dread, then the prompt "after": "gritty" (grit: weak adds 2 stress, strong 2 more dread), "steady",
 * "cursed" and "fragile" (grit: strong sets stress to a formula that divides by zero). Ada is in the
 * scene.
 *
 * @phpstan-type Entry array{id: string, kind: string, content: array<string, mixed>, flowStep: array{key: string}|null}
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(ResolvePendingFlowCheckHandler::class)]
#[CoversClass(FlowStepPendingResponse::class)]
final class CampaignFlowCheckPushApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9851';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f82';
    private const string PENDING = 'Keep or push the pending roll first.';

    private KernelBrowser $client;
    private CommandBus $commands;
    private RescriptableRandomNumberGenerator $random;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-25T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9451', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aPushableRollPendsWithItsEntryAndNothingElseIsRecordedOrApplied(): void
    {
        $before = $this->rolled('gritty');

        self::assertSame([['check', 'roll-it']], $this->kinds($this->journal()));
        self::assertSame(['key' => 'roll-it', 'pending' => ['entryId' => $this->journal()[0]['id'], 'characterId' => 'char-ada']], $this->stepOf($this->campaign()));
        self::assertNull(self::at($this->journal()[0], 'content', 'pool', 'push'));
        self::assertSame([$before[0], 0], [$this->values(), $this->dread()]);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function rollsThatCannotBePushed(): iterable
    {
        yield 'every die succeeded' => ['gritty', [5, 6, 5]];
        yield 'a check without a push' => ['steady', [1, 5, 2]];
        yield 'only Hunger dice failed' => ['thirsty', [5, 1, 2]];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('rollsThatCannotBePushed')]
    public function aRollThatCannotBePushedCompletesTheStepAtOnce(string $sceneType, array $faces): void
    {
        $this->inScene($sceneType);
        $this->random->script(...$faces);

        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'after', 'pending' => null], $this->stepOf($this->json()));
    }

    #[Test]
    public function pushingARollWhoseOnlyFailedDiceAreHungerDiceIsAConflict(): void
    {
        $this->inScene('thirsty');
        $this->random->script(5, 1, 2);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        self::assertResponseIsSuccessful();
        $before = $this->state();

        $this->push(['stepKey' => 'roll-it']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No roll of a check step waits for Keep or Push.'], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function keepCompletesTheStepOnTheRolledBandAndRecordsItLikeARollThatCompletesAtOnce(): void
    {
        $this->rolled('gritty');

        $this->keep(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'after', 'pending' => null], $this->stepOf($this->json()));
        $journal = $this->journal();
        self::assertSame([['check', 'roll-it'], ['sheet-change', 'roll-it'], ['flowOutcome', 'roll-it']], $this->kinds($journal));
        self::assertNull(self::at($journal[0], 'content', 'pool', 'push'));
        self::assertSame([['field' => 'stress', 'before' => 0, 'after' => 2]], array_map(static fn (mixed $change): array => ['field' => self::at($change, 'field'), 'before' => self::at($change, 'before'), 'after' => self::at($change, 'after')], (array) self::at($journal[1], 'content', 'changes')));
        self::assertSame([2, 1], [$this->values()['stress'] ?? null, $this->dread()]);
    }

    #[Test]
    public function aPushReRollsTheFailedDiceWithTheCostAndCompletesTheStepOnThePushedBand(): void
    {
        $this->rolled('gritty');
        $this->random->script(6, 6);

        $this->push(['stepKey' => 'roll-it', 'picks' => ['exhausted']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'after', 'pending' => null], $this->stepOf($this->json()));
        $journal = $this->journal();
        self::assertSame([['check', 'roll-it'], ['flowOutcome', 'roll-it']], $this->kinds($journal));
        $content = $journal[0]['content'];
        self::assertSame(['strong', 'Strong', 3, 3], [self::at($content, 'bandKey'), self::at($content, 'bandLabel'), self::at($content, 'total'), self::at($content, 'pool', 'push', 'successes')]);
        // The cost: 1 stress and the picked Condition; the step and the pushed band: 3 dread.
        self::assertSame([1, ['exhausted'], 3], [$this->values()['stress'] ?? null, $this->values()['physical'] ?? null, $this->dread()]);
    }

    #[Test]
    public function keepBranchesOnTheBestialFailureOfTheRollAheadOfItsBand(): void
    {
        $this->inScene('beastly');
        $this->random->script(1, 1, 1, 1);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'roll-it', 'pending' => ['entryId' => $this->journal()[0]['id'], 'characterId' => 'char-ada']], $this->stepOf($this->campaign()));
        self::assertSame(0, $this->dread());

        $this->keep(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        // The step adds 1 dread and the bestial failure 5; the "miss" band's 3 does not apply.
        self::assertSame(['key' => 'compulsion', 'pending' => null], $this->stepOf($this->json()));
        self::assertSame(6, $this->dread());
        self::assertSame('bestialFailure', self::at($this->journal()[0], 'content', 'special'));
    }

    #[Test]
    public function aPushBranchesOnTheSpecialResultOfThePushedRoll(): void
    {
        $this->inScene('beastly');
        $this->random->script(1, 1, 1, 1);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        // The cap is 1: the first failed regular die is re-rolled, to a 6. One success is no failure any more, so no bestial failure.
        $this->random->script(6);

        $this->push(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'after', 'pending' => null], $this->stepOf($this->json()));
        self::assertNull(self::at($this->journal()[0], 'content', 'special'));
        self::assertSame(1, $this->dread());
    }

    #[Test]
    public function aPushThatStillFailsWithAHungerOneStaysABestialFailure(): void
    {
        $this->inScene('beastly');
        $this->random->script(1, 1, 1, 1);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        $this->random->script(2);

        $this->push(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'compulsion', 'pending' => null], $this->stepOf($this->json()));
        self::assertSame(['bestialFailure', 6], [self::at($this->journal()[0], 'content', 'special'), $this->dread()]);
    }

    #[Test]
    public function keepOrPushWithoutAPendingRollIsAConflict(): void
    {
        $this->inScene('gritty');

        foreach (['keep', 'push'] as $command) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/%s', self::CAMPAIGN, $command), ['stepKey' => 'roll-it']);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => 'No roll of a check step waits for Keep or Push.'], $this->json());
        }
    }

    #[Test]
    public function keepOrPushOfAnotherStepIsAConflictAndKeepsNothing(): void
    {
        $before = $this->rolled('gritty');

        foreach (['keep', 'push'] as $command) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/%s', self::CAMPAIGN, $command), ['stepKey' => 'after']);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => 'The FlowRun is at step "roll-it", not at step "after".'], $this->json());
        }

        self::assertSame($before, $this->state());
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function commandsWhileARollPends(): iterable
    {
        yield 'an answer to the same step' => ['POST', 'flow-run/answer', ['stepKey' => 'roll-it', 'characterId' => 'char-ada']];
        yield 'a skip' => ['POST', 'flow-run/skip', ['stepKey' => 'roll-it']];
        yield 'a scene pick' => ['POST', 'flow-run/pick', ['sceneType' => 'gritty']];
        yield 'a scene pick by oracle' => ['POST', 'flow-run/pick/roll', null];
        yield 'end scene' => ['POST', 'flow-run/end-scene', ['sceneNumber' => 1]];
        yield 'move on' => ['POST', 'flow-run/move-on', ['phase' => 'roam']];
        yield 'pause' => ['POST', 'flow-run/pause', null];
        yield 'resume' => ['POST', 'flow-run/resume', null];
        yield 'end session' => ['POST', 'sessions/current/end', null];
        yield 'a Scene Type switch' => ['POST', 'scenes/current/scene-type', ['sceneType' => 'steady']];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[Test]
    #[DataProvider('commandsWhileARollPends')]
    public function nothingElseMovesTheFlowRunWhileARollPends(string $method, string $path, ?array $body): void
    {
        $before = $this->rolled('gritty');
        $this->random->script(6, 6, 6);

        $this->client->jsonRequest($method, \sprintf('/api/campaigns/%s/%s', self::CAMPAIGN, $path), $body ?? []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => self::PENDING], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aSessionCannotStartWhileTheOneOfTheRollIsUnderWay(): void
    {
        $before = $this->rolled('gritty');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', self::CAMPAIGN));

        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function trackersCharactersAndTheJournalCanChangeWhileARollPends(): void
    {
        $this->rolled('gritty');

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/trackers/dread', self::CAMPAIGN), ['value' => 4]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', self::CAMPAIGN), ['text' => 'Meanwhile.']);
        self::assertResponseStatusCodeSame(201);

        $this->keep(['stepKey' => 'roll-it']);

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->dread());
    }

    #[Test]
    public function theSheetCannotPushTheRollOfAStep(): void
    {
        $before = $this->rolled('gritty');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', self::CAMPAIGN, $before[2][0]['id']), ['picks' => ['hurt']]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A roll recorded by a Flow step cannot be pushed from the sheet.'], $this->json());
        self::assertSame($before, $this->state());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'keep without a step' => ['keep', '{}'];
        yield 'keep with a step that is no string' => ['keep', '{"stepKey": 1}'];
        yield 'push without a step' => ['push', '{"picks": []}'];
        yield 'picks that are no list' => ['push', '{"stepKey": "roll-it", "picks": "hurt"}'];
        yield 'a pick that is no string' => ['push', '{"stepKey": "roll-it", "picks": [1]}'];
        yield 'no JSON object' => ['push', '"roll-it"'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $command, string $body): void
    {
        $before = $this->rolled('gritty');

        $this->client->request('POST', \sprintf('/api/campaigns/%s/flow-run/%s', self::CAMPAIGN, $command), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->state());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function unprocessablePushes(): iterable
    {
        yield 'a pick missing' => ['gritty', ['stepKey' => 'roll-it'], 'The cost of the push marks an option of "physical": pick one.'];
        yield 'a pick not available' => ['gritty', ['stepKey' => 'roll-it', 'picks' => ['ghost']], '"ghost" is not an unmarked option of "physical"'];
        yield 'a cost that divides by zero' => ['cursed', ['stepKey' => 'roll-it'], 'The formula "{agility} / ({zero} - 1)" cannot be evaluated: The formula divides by zero.'];
        yield 'a band Effect that divides by zero' => ['fragile', ['stepKey' => 'roll-it', 'picks' => ['hurt']], 'The formula "{agility} / ({zero} - 1)" cannot be evaluated: The formula divides by zero.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessablePushes')]
    public function anUnprocessablePushKeepsNothingAndTheRollStillPends(string $sceneType, array $body, string $error): void
    {
        $before = $this->rolled($sceneType);
        $this->random->script(6, 6);

        $this->push($body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($error, $this->error());
        self::assertSame($before, $this->state());
    }

    /**
     * @return iterable<string, array{string, string}> the stored change (a SQL expression over content) and the error
     */
    public static function entriesThatCannotBePushed(): iterable
    {
        yield 'pushed already' => ["(SELECT pushed.content FROM play_journal_entry pushed WHERE jsonb_typeof(pushed.content->'pool'->'push') = 'object')", 'was pushed already, and a roll is pushed once.'];
        yield 'a check the release no longer pushes' => ["jsonb_set(content, '{checkKey}', '\"steady\"')", 'The pinned release has no pushable pool check "steady" with the pool that was rolled.'];
        yield 'a Character who left' => ["jsonb_set(content, '{characterId}', '\"char-gone\"')", 'The Character "char-gone" who rolled is no longer in the campaign.'];
    }

    #[Test]
    #[DataProvider('entriesThatCannotBePushed')]
    public function anEntryThatCannotBePushedIsAConflictAndKeepsNothing(string $change, string $error): void
    {
        $rolled = $this->rolled('gritty')[2][0]['id'];
        $this->givenAPushedEntryElsewhere();
        $this->connection()->executeStatement(\sprintf('UPDATE play_journal_entry SET content = %s WHERE id = ?', $change), [$rolled]);
        $before = $this->state();

        $this->random->script(6, 6);
        $this->push(['stepKey' => 'roll-it', 'picks' => ['hurt']]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($error, $this->error());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aKeepWhoseBandEffectDividesByZeroKeepsNothingAndTheRollStillPends(): void
    {
        $before = $this->rolled('brittle');

        $this->keep(['stepKey' => 'roll-it']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The formula "{agility} / ({zero} - 1)" cannot be evaluated: The formula divides by zero.', $this->error());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aKeepWhileAnotherRequestSavesTheCampaignIsAConflictAndKeepsNothing(): void
    {
        $before = $this->rolled('gritty');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement('UPDATE play_campaign SET version = version + 1 WHERE id = ?', [CampaignFlowCheckPushApiTest::campaignId()]);
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->keep(['stepKey' => 'roll-it']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN)], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aPushWhileAnotherRequestSavesTheCampaignIsAConflictAndKeepsNothing(): void
    {
        $before = $this->rolled('gritty');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement('UPDATE play_campaign SET version = version + 1 WHERE id = ?', [CampaignFlowCheckPushApiTest::campaignId()]);
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->random->script(6, 6);
            $this->push(['stepKey' => 'roll-it', 'picks' => ['hurt']]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN)], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function anUnknownCampaignIsNotFound(): void
    {
        foreach (['keep', 'push'] as $command) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/%s', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fffff', $command), ['stepKey' => 'roll-it']);

            self::assertResponseStatusCodeSame(404);
        }
    }

    #[Test]
    public function anUpgradeDropsThePendingRollAndItsEntryStaysAsRolled(): void
    {
        $this->rolled('gritty');
        $rolled = $this->journal();
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9452', ReleaseArrays::with($this->release(), 'gameSystem.name', 'Pushing again'), false));
        $this->client->request('GET', \sprintf('/api/campaigns/%s/release-upgrade', self::CAMPAIGN));
        self::assertResponseIsSuccessful();
        $fingerprint = self::at($this->json(), 'plan', 'fingerprint');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/release-upgrade', self::CAMPAIGN), ['toVersion' => 2, 'fingerprint' => $fingerprint]);

        self::assertResponseIsSuccessful();
        // Guidance restarts on the new release: at the scene pick, with no step and no roll pending.
        self::assertNull(self::at($this->campaign(), 'flowRun', 'step'));
        self::assertSame($rolled, $this->journal());
        $this->keep(['stepKey' => 'roll-it']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No roll of a check step waits for Keep or Push.'], $this->json());
    }

    public static function campaignId(): string
    {
        return self::CAMPAIGN;
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $bands = [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 1], ['key' => 'strong', 'label' => 'Strong']];
        $pool = ['count' => 3, 'sides' => 6, 'successAt' => 5];
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-dice-pools'), 'gameSystem.key', 'pushing');
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [
            ['key' => 'agility', 'label' => 'Agility', 'type' => 'number', 'default' => 3],
            ['key' => 'zero', 'label' => 'Zero', 'type' => 'number', 'default' => 1],
            ['key' => 'stress', 'label' => 'Stress', 'type' => 'number', 'min' => 0, 'max' => 10],
            ['key' => 'physical', 'label' => 'Physical', 'type' => 'conditions', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'exhausted', 'label' => 'Exhausted']]],
        ]]);
        $content = ReleaseArrays::with($content, 'checks', [
            ['key' => 'grit', 'label' => 'Grit', 'pool' => $pool, 'bands' => $bands, 'push' => ['label' => 'Take a Condition', 'effects' => [
                ['kind' => 'sheet', 'field' => 'stress', 'op' => 'add', 'value' => 1],
                ['kind' => 'sheet', 'field' => 'physical', 'op' => 'mark'],
            ]]],
            ['key' => 'steady', 'label' => 'Steady', 'pool' => $pool, 'bands' => $bands],
            ['key' => 'bloodlust', 'label' => 'Bloodlust', 'pool' => ['count' => 4, 'criticals' => ['bonus' => 2], 'hunger' => ['count' => 1], 'sides' => 6, 'successAt' => 5], 'bands' => $bands, 'push' => ['label' => 'Spend Willpower', 'maxDice' => 1]],
            ['key' => 'thirst', 'label' => 'Thirst', 'pool' => ['hunger' => ['count' => 2]] + $pool, 'bands' => $bands, 'push' => ['label' => 'Push']],
            ['key' => 'cursed', 'label' => 'Cursed', 'pool' => $pool, 'bands' => $bands, 'push' => ['label' => 'Pay', 'effects' => [['kind' => 'sheet', 'field' => 'stress', 'op' => 'set', 'value' => ['formula' => '{agility} / ({zero} - 1)']]]]],
        ]);
        $dread = static fn (int $by): array => ['kind' => 'tracker', 'tracker' => 'dread', 'op' => 'add', 'value' => $by];
        $stress = static fn (array|int $value, string $op = 'add'): array => ['kind' => 'sheet', 'field' => 'stress', 'op' => $op, 'value' => $value];
        $step = static fn (string $check, array $outcomes = []): array => [
            ['key' => 'roll-it', 'kind' => 'check', 'title' => 'Roll it', 'check' => $check, 'effects' => [$dread(1)], 'outcomes' => $outcomes],
            ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'],
        ];
        $sceneTypes = [
            'gritty' => $step('grit', [['band' => 'weak', 'effects' => [$stress(2)]], ['band' => 'strong', 'effects' => [$dread(2)]]]),
            'steady' => $step('steady'),
            'thirsty' => $step('thirst'),
            'cursed' => $step('cursed'),
            'fragile' => $step('grit', [['band' => 'strong', 'effects' => [$stress(['formula' => '{agility} / ({zero} - 1)'], 'set')]]]),
            'brittle' => $step('grit', [['band' => 'weak', 'effects' => [$stress(['formula' => '{agility} / ({zero} - 1)'], 'set')]]]),
        ];
        // The step branches on a bestial failure ahead of the band, to "compulsion", the last step.
        $sceneTypes['beastly'] = [
            ['key' => 'roll-it', 'kind' => 'check', 'title' => 'Roll it', 'check' => 'bloodlust', 'effects' => [$dread(1)], 'outcomes' => [['band' => 'miss', 'effects' => [$dread(3)]], ['special' => 'bestialFailure', 'next' => 'compulsion', 'effects' => [$dread(5)]]]],
            ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'],
            ['key' => 'compulsion', 'kind' => 'prompt', 'title' => 'The Beast takes over'],
        ];
        $content = ReleaseArrays::with($content, 'sceneTypes', array_map(
            static fn (string $key, array $play): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_keys($sceneTypes)]],
        ]]]);

        return $release;
    }

    /**
     * A guided campaign in a scene of this Scene Type, with Ada in it.
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The climb', 'pushing', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], []));
    }

    /**
     * Ada rolls 1, 5 and 2 on "roll-it": one success, "weak", with dice that failed; the roll pends.
     *
     * @return array{array<mixed>, int, list<Entry>, mixed} the state before Keep or Push (see state())
     */
    private function rolled(string $sceneType): array
    {
        $this->inScene($sceneType);
        $this->random->script(1, 5, 2);
        $this->answer(['stepKey' => 'roll-it', 'characterId' => 'char-ada']);
        self::assertResponseIsSuccessful();

        return $this->state();
    }

    /**
     * A second campaign with a pushed roll of "grit" (1, 5, 2 pushed to 6, 6), so a stored push can be copied.
     */
    private function givenAPushedEntryElsewhere(): void
    {
        $other = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9852';
        $this->dispatch(new CreateCampaign($other, $this->userId, 'Elsewhere', 'pushing', null));
        $this->dispatch(new StartSession($other, $this->userId));
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $other), ['title' => 'Away']);
        $this->dispatch(new AddCharacter($other, $this->userId, 'char-bo', 'Bo', [], [], []));
        $this->random->script(1, 5, 2);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $other), ['check' => 'grit', 'character' => 'char-bo']);
        self::assertResponseStatusCodeSame(201);
        $entry = $this->json()['id'] ?? null;
        $this->random->script(6, 6);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $other, \is_string($entry) ? $entry : ''), ['picks' => ['hurt']]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Everything Keep or Push can change: Ada's values, dread, the journal and the FlowRun.
     *
     * @return array{array<mixed>, int, list<Entry>, mixed}
     */
    private function state(): array
    {
        return [$this->values(), $this->dread(), $this->journal(), $this->campaign()['flowRun'] ?? null];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answer(array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/answer', self::CAMPAIGN), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function keep(array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/keep', self::CAMPAIGN), $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function push(array $body): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/push', self::CAMPAIGN), $body);
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<mixed> $campaign
     *
     * @return array{key: mixed, pending: mixed}
     */
    private function stepOf(array $campaign): array
    {
        $step = self::at($campaign, 'flowRun', 'step');
        self::assertIsArray($step);

        return ['key' => $step['key'] ?? null, 'pending' => \array_key_exists('pending', $step) ? $step['pending'] : 'absent'];
    }

    /**
     * @param list<Entry> $journal
     *
     * @return list<array{string, ?string}>
     */
    private function kinds(array $journal): array
    {
        return array_map(static fn (array $entry): array => [$entry['kind'], $entry['flowStep']['key'] ?? null], $journal);
    }

    /**
     * @return array<mixed> Ada's values by field key
     */
    private function values(): array
    {
        foreach ((array) self::at($this->campaign(), 'characters') as $character) {
            $values = self::at($character, 'values');
            if ('char-ada' === self::at($character, 'id') && \is_array($values)) {
                return $values;
            }
        }

        self::fail('Ada is not in the campaign.');
    }

    private function dread(): int
    {
        foreach ((array) self::at($this->campaign(), 'trackers') as $tracker) {
            $value = self::at($tracker, 'value');
            if ('dread' === self::at($tracker, 'key') && \is_int($value)) {
                return $value;
            }
        }

        self::fail('No dread.');
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
     * @return list<Entry>
     */
    private function journal(): array
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', self::CAMPAIGN));
        self::assertResponseIsSuccessful();
        /** @var list<Entry> $journal */
        $journal = $this->json();

        return $journal;
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

    /**
     * The value at the path of keys in decoded JSON, null where it has none.
     */
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

    private function error(): string
    {
        $error = self::at($this->json(), 'error');

        return \is_string($error) ? $error : '';
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
