<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\CheckPusher;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\PushCheckHandler;
use App\Play\Infrastructure\Http\CheckChallengeBurnContentResponse;
use App\Play\Infrastructure\Http\CheckChallengeContentResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Randomness\Domain\RandomNumberGenerator;
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
 * Burning momentum from the sheet over the Play API (ADR 0049), on valid/v2-challenge-dice with the fields "banes"
 * (a conditions field) and the checks "edge" (1d6 + {edge} against 2d10, capped at 10; burning sets momentum to
 * {momentum-reset}, 2), "bane" (burning also marks a bane the player picks), "capped" (a cap of 8) and "fulfill" (a
 * progress roll, no burn). Ada has edge 2 and the momentum she is given.
 *
 * @phpstan-type Entry array{id: string, content: array{bandKey: string, total: int, special: string|null, challenge: array{score: int, momentum: int|null, dice: list<array{face: int, beaten: bool}>, burn: array<string, mixed>|null}}}
 */
#[CoversClass(JournalController::class)]
#[CoversClass(PushCheckHandler::class)]
#[CoversClass(CheckPusher::class)]
#[CoversClass(CheckChallengeContentResponse::class)]
#[CoversClass(CheckChallengeBurnContentResponse::class)]
final class JournalChallengeBurnApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string COST = 'Burn momentum';

    private KernelBrowser $client;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-25T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80'));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);

        $reset = ['kind' => 'sheet', 'field' => 'momentum', 'op' => 'set', 'value' => ['formula' => '{momentum-reset}']];
        $edge = ReleaseViews::fixtureContent('valid/v2-challenge-dice');
        $edge = ReleaseArrays::with($edge, 'sheet.fields.4', ['key' => 'banes', 'label' => 'Banes', 'type' => 'conditions', 'options' => [['key' => 'maimed', 'label' => 'Maimed'], ['key' => 'corrupted', 'label' => 'Corrupted']]]);
        $checks = $edge['checks'] ?? [];
        \assert(\is_array($checks));
        $first = $checks[0];
        \assert(\is_array($first));
        $edge = ReleaseArrays::with($edge, 'checks', [
            $first,
            $checks[1],
            ['key' => 'bane', 'label' => 'Bane'] + array_diff_key($first, ['key' => 1, 'label' => 1, 'push' => 1]) + ['push' => ['label' => 'Burn and bear a bane', 'effects' => [$reset, ['kind' => 'sheet', 'field' => 'banes', 'op' => 'mark']]]],
            ['key' => 'capped', 'label' => 'Capped', 'challenge' => ['count' => 2, 'sides' => 10, 'scoreMax' => 8, 'momentum' => 'momentum']] + array_diff_key($first, ['key' => 1, 'label' => 1, 'challenge' => 1]),
        ]);
        $bus = $container->get(CommandBus::class);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9304', $edge, false));
        $bus->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignTellsWhichChecksBurnAndWhereMomentumLives(): void
    {
        [$id] = $this->campaignInAScene();

        /** @var list<array{key: string, push: array<string, mixed>|null, challenge: array<string, mixed>}> $list */
        $list = $this->get('/api/campaigns/'.$id)['checks'];
        $checks = array_column($list, null, 'key');

        self::assertSame(['label' => self::COST, 'picks' => [], 'maxDice' => null], $checks['edge']['push']);
        self::assertSame(['label' => 'Burn and bear a bane', 'picks' => ['banes'], 'maxDice' => null], $checks['bane']['push']);
        self::assertSame('momentum', $checks['edge']['challenge']['momentum']);
        self::assertNull($checks['fulfill']['push']);
        self::assertNull($checks['fulfill']['challenge']['momentum']);
    }

    #[Test]
    public function aBurnMakesTheMomentumTheScorePaysTheCostAfterReadingItAndKeepsTheFirstRoll(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        self::assertSame(['weak', 1], [$entry['content']['bandKey'], $entry['content']['total']]);

        $this->push($id, $entry['id']);

        self::assertResponseIsSuccessful();
        $burned = $this->entryJson();
        self::assertSame(['strong', 2], [$burned['content']['bandKey'], $burned['content']['total']]);
        self::assertSame(6, $burned['content']['challenge']['score'], 'The score of the first roll stays.');
        self::assertSame($entry['content']['challenge']['dice'], $burned['content']['challenge']['dice']);
        self::assertSame([
            'label' => self::COST,
            'momentum' => 10,
            'score' => 10,
            'rolledBandKey' => 'weak',
            'rolledBandLabel' => 'Weak hit',
            'changes' => [['field' => 'momentum', 'before' => 10, 'after' => 2, 'dice' => null]],
        ], $burned['content']['challenge']['burn']);
        self::assertSame(2, $this->values($id)['momentum'], 'The cost reset the momentum after it was read.');
        self::assertSame($burned, $this->journal($id)[0], 'What was stored reads back as the same entry.');
    }

    #[Test]
    public function anUnburnedChallengeEntryReadsWithANullBurn(): void
    {
        [$id, $ada] = $this->campaignInAScene();

        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);

        self::assertNull($entry['content']['challenge']['burn']);
        self::assertNull($this->journal($id)[0]['content']['challenge']['burn']);
    }

    #[Test]
    public function aStoredRowWithoutABurnReadsAsNotBurned(): void
    {
        [$id, $ada] = $this->campaignInAScene();
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $this->connection()->executeStatement("UPDATE play_journal_entry SET content = content #- '{challenge,burn}' WHERE id = ?", [$entry['id']]);

        self::assertNull($this->journal($id)[0]['content']['challenge']['burn']);
    }

    #[Test]
    public function aMomentumAboveTheCapBurnsAtTheCap(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'capped', [1, 3, 7]);
        self::assertSame(0, $entry['content']['total']);

        $this->push($id, $entry['id']);

        self::assertResponseIsSuccessful();
        $burned = $this->entryJson();
        self::assertSame([10, 8, 2], [$burned['content']['challenge']['burn']['momentum'] ?? null, $burned['content']['challenge']['burn']['score'] ?? null, $burned['content']['total']]);
    }

    #[Test]
    public function aMatchStaysAMatchThroughTheBurn(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [1, 5, 5]);
        self::assertSame('match', $entry['content']['special']);

        $this->push($id, $entry['id']);

        self::assertResponseIsSuccessful();
        self::assertSame(['match', 2, 'strong'], [$this->entryJson()['content']['special'], $this->entryJson()['content']['total'], $this->entryJson()['content']['bandKey']]);
    }

    /**
     * @return iterable<string, array{list<int>, int}> the dice rolled and the momentum on the sheet
     */
    public static function burnsThatBeatNoMoreDice(): iterable
    {
        yield 'momentum below the score' => [[4, 3, 9], 2];
        yield 'momentum equal to a die' => [[4, 3, 9], 9];
        yield 'zero momentum' => [[4, 3, 9], 0];
        yield 'negative momentum' => [[4, 3, 9], -2];
        yield 'a score that beats both dice already' => [[6, 3, 4], 10];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('burnsThatBeatNoMoreDice')]
    public function aBurnThatBeatsNoMoreDiceIsAConflictAndChangesNothing(array $faces, int $momentum): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => $momentum]);
        $entry = $this->roll($id, $ada, 'edge', $faces);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The momentum on the sheet no longer beats more challenge dice than the score of the roll: there is nothing to burn.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aMomentumEditedByHandAfterTheRollIsCheckedAgainAndChangesNothing(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $ada), ['name' => 'Ada', 'tags' => [], 'freeTags' => [], 'values' => ['vow' => 28, 'momentum' => 3]]);
        self::assertResponseIsSuccessful();
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($before, $this->state($id));
        self::assertSame(3, $this->values($id)['momentum']);
        self::assertNull($this->journal($id)[0]['content']['challenge']['burn']);
    }

    #[Test]
    public function aRollIsBurnedOnce(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $this->push($id, $entry['id']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $ada), ['name' => 'Ada', 'tags' => [], 'freeTags' => [], 'values' => ['vow' => 28, 'momentum' => 10]]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The check of journal entry "%s" was pushed already, and a roll is pushed once.', $entry['id'])], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function onlyTheLatestEntryOfTheJournalCanBeBurned(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $this->roll($id, $ada, 'fulfill', [3, 8]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Only the latest entry of the journal can be pushed.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aProgressRollCannotBeBurned(): void
    {
        [$id, $ada] = $this->campaignInAScene();
        $entry = $this->roll($id, $ada, 'fulfill', [3, 8]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'The pinned release has no burnable challenge check "fulfill" with the momentum and challenge dice that were rolled.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    /**
     * @return iterable<string, array{string, string}> the stored change (a SQL expression over content) and the check it names
     */
    public static function changedChecks(): iterable
    {
        yield 'a check the release dropped' => ["jsonb_set(content, '{checkKey}', '\"ghost\"')", 'ghost'];
        yield 'a check without a push' => ["jsonb_set(content, '{checkKey}', '\"fulfill\"')", 'fulfill'];
        yield 'another number of challenge dice' => ["jsonb_set(content, '{challenge,dice}', (content->'challenge'->'dice') || '[{\"face\": 9, \"beaten\": false}]'::jsonb)", 'edge'];
        yield 'other challenge sides' => ["jsonb_set(content, '{challenge,sides}', '12')", 'edge'];
        yield 'another cap' => ["jsonb_set(content, '{challenge,scoreMax}', 'null')", 'edge'];
        yield 'a roll that named no momentum' => ["jsonb_set(content, '{challenge,momentum}', 'null')", 'edge'];
    }

    #[Test]
    #[DataProvider('changedChecks')]
    public function aRollOfACheckTheReleaseChangedIsRefusedAndChangesNothing(string $change, string $key): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $this->connection()->executeStatement(\sprintf('UPDATE play_journal_entry SET content = %s WHERE id = ?', $change), [$entry['id']]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The pinned release has no burnable challenge check "%s" with the momentum and challenge dice that were rolled.', $key)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function theCostOfABurnTakesThePlayersPickAfterTheMomentumIsRead(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'bane', [4, 3, 9]);

        $this->push($id, $entry['id'], ['picks' => ['corrupted']]);

        self::assertResponseIsSuccessful();
        /** @var array{momentum?: int, changes?: list<array{field: string}>} $burn */
        $burn = $this->entryJson()['content']['challenge']['burn'] ?? [];
        self::assertSame(10, $burn['momentum'] ?? null);
        self::assertSame(['momentum', 'banes'], array_column($burn['changes'] ?? [], 'field'));
        $values = $this->values($id);
        self::assertSame([2, ['corrupted']], [$values['momentum'], $values['banes']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the body of the burn and the refusal
     */
    public static function picksThatDoNotFit(): iterable
    {
        yield 'no pick' => [[], 'The cost of the push marks an option of "banes": pick one.'];
        yield 'an unknown option' => [['picks' => ['cursed']], '"cursed" is not an unmarked option of "banes" (unmarked: maimed, corrupted).'];
        yield 'an extra pick' => [['picks' => ['maimed', 'corrupted']], 'The cost of the push takes no more picks (it picks among banes); unused: corrupted.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('picksThatDoNotFit')]
    public function picksThatDoNotFitTheCostAreRefusedAndNothingChanges(array $body, string $message): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'bane', [4, 3, 9]);
        $before = $this->state($id);

        $this->push($id, $entry['id'], $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $message], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aCharacterWhoLeftTheCampaignCannotBurn(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $gone = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fa001';
        $this->connection()->executeStatement('UPDATE play_journal_entry SET content = jsonb_set(content, ?, ?::jsonb) WHERE id = ?', ['{characterId}', json_encode($gone, \JSON_THROW_ON_ERROR), $entry['id']]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The Character "%s" who rolled is no longer in the campaign.', $gone)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aBurnWhileAnotherRequestSavesTheCampaignIsAConflictAndKeepsNothing(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => 10]);
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);
        $before = $this->state($id);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $id) {
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
            $this->push($id, $entry['id']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{string, string} the campaign and the Character
     */
    private function campaignInAScene(array $values = []): array
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The vow', 'gameSystemKey' => 'challenge-dice']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->string($this->json()['id'] ?? null);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'On the road']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Ada', 'values' => ['vow' => 28] + $values]);
        self::assertResponseStatusCodeSame(201);

        return [$id, $this->string($this->json()['id'] ?? null)];
    }

    /**
     * @param list<int> $faces
     *
     * @return Entry
     */
    private function roll(string $campaignId, string $character, string $check, array $faces): array
    {
        $this->random->script(...$faces);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $campaignId), ['check' => $check, 'character' => $character]);
        self::assertResponseStatusCodeSame(201);

        return $this->entryJson();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function push(string $campaignId, string $entryId, array $body = []): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $campaignId, $entryId), $body);
    }

    /**
     * @return array{mixed, list<Entry>} the Characters of the campaign and its journal
     */
    private function state(string $campaignId): array
    {
        return [$this->get('/api/campaigns/'.$campaignId)['characters'] ?? null, $this->journal($campaignId)];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(string $campaignId): array
    {
        /** @var list<array{values: array<string, mixed>}> $characters */
        $characters = $this->get('/api/campaigns/'.$campaignId)['characters'] ?? [];

        return $characters[0]['values'];
    }

    /**
     * @return list<Entry>
     */
    private function journal(string $campaignId): array
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $campaignId));
        self::assertResponseIsSuccessful();

        /** @var list<Entry> $journal */
        $journal = $this->json();

        return $journal;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return Entry
     */
    private function entryJson(): array
    {
        /** @var Entry $entry */
        $entry = $this->json();

        return $entry;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
