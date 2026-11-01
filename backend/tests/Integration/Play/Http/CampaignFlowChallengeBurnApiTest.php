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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A challenge roll whose momentum can be burned pends until Keep or Push (ADR 0041, ADR 0049), over the Play
 * API, on valid/v2-challenge-dice with the field "banes" and the check "bane" (its burn also marks a bane the player
 * picks). The Scene Types "journey" (the step "strike" rolls "edge": a miss takes 1 momentum, a strong hit adds 1;
 * then "fulfil"), "baned" ("strike" rolls "bane") and "chosen" ("strike" rolls "edge" or "bane"). Burning "edge"
 * sets momentum to 2 (its reset). Ada has edge 2 and is in the scene.
 *
 * @phpstan-type Entry array{id: string, kind: string, content: array<string, mixed>}
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(ResolvePendingFlowCheckHandler::class)]
final class CampaignFlowChallengeBurnApiTest extends WebTestCase
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
        $container->set(Clock::class, new FixedClock('2026-10-25T09:00:00+00:00'));
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

    #[Test]
    public function aRollWhoseMomentumBeatsMoreDicePendsWithItsEntryAndNothingElseIsApplied(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);

        $entries = $this->journal();
        self::assertSame(['check'], array_column($entries, 'kind'));
        self::assertSame(['key' => 'strike', 'pending' => ['entryId' => $entries[0]['id'], 'characterId' => 'char-ada']], $this->stepOf($this->campaign()));
        self::assertNull($this->burnOf($entries[0]));
        self::assertSame(10, $this->values()['momentum']);
    }

    #[Test]
    public function aRollWhoseMomentumCannotImproveCompletesTheStepAtOnce(): void
    {
        $this->inScene('journey', 2);
        $this->random->script(4, 3, 9);

        $this->answer(['stepKey' => 'strike', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'fulfil', 'pending' => null], $this->stepOf($this->json()));
    }

    #[Test]
    public function burningCompletesTheStepOnTheBurnedBandAndPaysTheCostBeforeItsOutcome(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);

        $this->push(['stepKey' => 'strike']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'fulfil', 'pending' => null], $this->stepOf($this->json()));
        $entries = $this->journal();
        $burn = $this->burnOf($entries[0]);
        self::assertSame([10, 10, 'weak'], [$burn['momentum'] ?? null, $burn['score'] ?? null, $burn['rolledBandKey'] ?? null]);
        self::assertSame(['strong', 2], [$entries[0]['content']['bandKey'], $entries[0]['content']['total']]);
        // The cost reset the momentum to 2 after it was read, then the strong outcome added 1.
        self::assertSame(3, $this->values()['momentum']);
    }

    #[Test]
    public function keepingCompletesTheStepOnTheRolledBandAndTheMatchChangesNoOutcome(): void
    {
        $this->rolled('journey', 10, [1, 5, 5]);

        $this->keep(['stepKey' => 'strike']);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'fulfil', 'pending' => null], $this->stepOf($this->json()));
        $entries = $this->journal();
        self::assertNull($this->burnOf($entries[0]));
        self::assertSame(['match', 'miss'], [$entries[0]['content']['special'], $entries[0]['content']['bandKey']]);
        // The miss took 1 momentum: the band's outcome applies although the roll is a match.
        self::assertSame(9, $this->values()['momentum']);
    }

    #[Test]
    public function burningAMatchKeepsTheMatchAndTakesTheOutcomeOfTheBurnedBand(): void
    {
        $this->rolled('journey', 10, [1, 5, 5]);

        $this->push(['stepKey' => 'strike']);

        self::assertResponseIsSuccessful();
        $entry = $this->journal()[0];
        self::assertSame(['match', 'strong'], [$entry['content']['special'], $entry['content']['bandKey']]);
        self::assertSame(3, $this->values()['momentum']);
    }

    #[Test]
    public function aMomentumEditedByHandWhileTheRollPendsIsCheckedAgainAndChangesNothing(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/char-ada', self::CAMPAIGN), ['name' => 'Ada', 'tags' => [], 'freeTags' => [], 'values' => ['momentum' => 3]]);
        self::assertResponseIsSuccessful();
        $before = $this->state();

        $this->push(['stepKey' => 'strike']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The momentum on the sheet no longer beats more challenge dice than the score of the roll: there is nothing to burn.'], $this->json());
        self::assertSame($before, $this->state());
        self::assertSame('strike', $this->stepOf($this->campaign())['key']);
    }

    #[Test]
    public function aBurnedRollCannotBeBurnedAgain(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);
        $this->push(['stepKey' => 'strike']);
        self::assertResponseIsSuccessful();
        $before = $this->state();

        $this->push(['stepKey' => 'strike']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aRollOfACheckTheReleaseChangedIsRefusedAndChangesNothing(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);
        $entry = $this->journal()[0];
        $this->connection()->executeStatement("UPDATE play_journal_entry SET content = jsonb_set(content, '{challenge,sides}', '12') WHERE id = ?", [$entry['id']]);
        $before = $this->state();

        $this->push(['stepKey' => 'strike']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The pinned release has no burnable challenge check "edge" with the momentum and challenge dice that were rolled.'], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function aCharacterWhoLeftTheCampaignCannotBurnThePendingRoll(): void
    {
        $this->rolled('journey', 10, [4, 3, 9]);
        $entry = $this->journal()[0];
        $this->connection()->executeStatement("UPDATE play_journal_entry SET content = jsonb_set(content, '{characterId}', '\"char-gone\"') WHERE id = ?", [$entry['id']]);
        $before = $this->state();

        $this->push(['stepKey' => 'strike']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The Character "char-gone" who rolled is no longer in the campaign.'], $this->json());
        self::assertSame($before, $this->state());
    }

    #[Test]
    public function theCostOfABurnTakesThePlayersPickAndAMissingOneIsRefused(): void
    {
        $this->rolled('baned', 10, [4, 3, 9]);
        $before = $this->state();

        $this->push(['stepKey' => 'strike']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'The cost of the push marks an option of "banes": pick one.'], $this->json());
        self::assertSame($before, $this->state());

        $this->push(['stepKey' => 'strike', 'picks' => ['maimed']]);

        self::assertResponseIsSuccessful();
        $values = $this->values();
        self::assertSame([2, ['maimed']], [$values['momentum'], $values['banes']]);
        /** @var list<array{field: string}> $changes */
        $changes = $this->burnOf($this->journal()[0])['changes'] ?? [];
        self::assertSame(['momentum', 'banes'], array_column($changes, 'field'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checksOfTheSet(): iterable
    {
        yield 'a check without a pick' => ['edge'];
        yield 'a check with a pick' => ['bane'];
    }

    #[Test]
    #[DataProvider('checksOfTheSet')]
    public function aCheckStepOfASetBurnsTheCheckThePlayerPicked(string $check): void
    {
        $this->inScene('chosen', 10);
        $this->random->script(4, 3, 9);
        $this->answer(['stepKey' => 'strike', 'characterId' => 'char-ada', 'checkKey' => $check]);
        self::assertResponseIsSuccessful();
        self::assertSame('strike', $this->stepOf($this->json())['key']);

        $this->push(['stepKey' => 'strike'] + ('bane' === $check ? ['picks' => ['corrupted']] : []));

        self::assertResponseIsSuccessful();
        $entry = $this->journal()[0];
        self::assertSame([$check, 'strong'], [$entry['content']['checkKey'], $entry['content']['bandKey']]);
        self::assertNotNull($this->burnOf($entry));
    }

    /**
     * @param list<int> $faces
     */
    private function rolled(string $sceneType, int $momentum, array $faces): void
    {
        $this->inScene($sceneType, $momentum);
        $this->random->script(...$faces);
        $this->answer(['stepKey' => 'strike', 'characterId' => 'char-ada']);
        self::assertResponseIsSuccessful();
    }

    private function inScene(string $sceneType, int $momentum): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The vow', 'challenge-dice', 'tour'));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], ['momentum' => $momentum]));
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $content = ReleaseViews::fixtureContent('valid/v2-challenge-dice');
        $content = ReleaseArrays::with($content, 'sheet.fields.4', ['key' => 'banes', 'label' => 'Banes', 'type' => 'conditions', 'options' => [['key' => 'maimed', 'label' => 'Maimed'], ['key' => 'corrupted', 'label' => 'Corrupted']]]);
        $checks = $content['checks'] ?? [];
        \assert(\is_array($checks) && \is_array($checks[0]));
        $reset = ['kind' => 'sheet', 'field' => 'momentum', 'op' => 'set', 'value' => ['formula' => '{momentum-reset}']];
        $content = ReleaseArrays::with($content, 'checks', [$checks[0], $checks[1], ['key' => 'bane', 'label' => 'Bane'] + array_diff_key($checks[0], ['key' => 1, 'label' => 1, 'push' => 1]) + ['push' => ['label' => 'Burn and bear a bane', 'effects' => [$reset, ['kind' => 'sheet', 'field' => 'banes', 'op' => 'mark']]]]]);
        $scenes = $content['sceneTypes'] ?? [];
        \assert(\is_array($scenes) && \is_array($scenes[0]));
        $strike = static fn (string $key, array $step): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'Strike.', 'oracles' => [], 'setup' => [], 'play' => [$step], 'closing' => []];
        $step = ['key' => 'strike', 'kind' => 'check', 'title' => 'Strike'];

        $content = ReleaseArrays::with($content, 'sceneTypes', [
            $scenes[0],
            $strike('baned', $step + ['check' => 'bane']),
            $strike('chosen', $step + ['checks' => ['edge', 'bane']]),
        ]);

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['journey', 'baned', 'chosen']]],
        ]]]);

        return $release;
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
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

    /**
     * @param array<mixed> $campaign
     *
     * @return array{key: mixed, pending: mixed}
     */
    private function stepOf(array $campaign): array
    {
        /** @var array{flowRun: array{step: array<string, mixed>}} $campaign */
        $step = $campaign['flowRun']['step'];

        return ['key' => $step['key'] ?? null, 'pending' => \array_key_exists('pending', $step) ? $step['pending'] : 'absent'];
    }

    /**
     * @param Entry $entry
     *
     * @return array<string, mixed>|null
     */
    private function burnOf(array $entry): ?array
    {
        $challenge = $entry['content']['challenge'] ?? null;
        self::assertIsArray($challenge);
        $burn = $challenge['burn'] ?? null;
        self::assertTrue(null === $burn || \is_array($burn));

        /* @var array<string, mixed>|null $burn */
        return $burn;
    }

    /**
     * @return array{mixed, mixed, list<Entry>}
     */
    private function state(): array
    {
        $campaign = $this->campaign();

        return [$campaign['characters'] ?? null, $campaign['flowRun'] ?? null, $this->journal()];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        /** @var list<array{values: array<string, mixed>}> $characters */
        $characters = $this->campaign()['characters'] ?? [];

        return $characters[0]['values'];
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

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }
}
