<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Infrastructure\Http\CheckContentResponse;
use App\Play\Infrastructure\Http\CheckPoolContentResponse;
use App\Play\Infrastructure\Http\CheckPushContentResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Hunger dice, critical pairs, special results and the capped push over the Play API (ADR 0044), on a
 * release "night" with the sheet hunger (default 2) and zero (0), and the checks "persuade" (5d10
 * succeeding on 6, 2 critical bonus, Hunger dice from {hunger}, difficulty 3 by default, push capped
 * at 2 dice), "frenzy" (3d10, Hunger {hunger} / {zero}) and "uncapped" (4d10, 1 Hunger die, push).
 *
 * @phpstan-type Content array{bandKey: string, total: int, diceTotal: int, special: string|null, pool: array{dice: list<array<string, mixed>>, successes: int, criticalsBonus: int, special: string|null, push: array<string, mixed>|null}}
 * @phpstan-type Entry array{id: string, content: Content}
 */
#[CoversClass(JournalController::class)]
#[CoversClass(CheckContentResponse::class)]
#[CoversClass(CheckPoolContentResponse::class)]
#[CoversClass(CheckPushContentResponse::class)]
final class JournalHungerDiceApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BANDS = 'bands';

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

        $bands = [['key' => 'fail', 'label' => 'Failure', 'upTo' => ['formula' => '{check:difficulty} - 1']], ['key' => 'win', 'label' => 'Win']];
        $pool = ['sides' => 10, 'successAt' => 6];
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-dice-pools'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'flows', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'night');
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [
            ['key' => 'hunger', 'label' => 'Hunger', 'type' => 'number', 'min' => 0, 'max' => 5, 'default' => 2],
            ['key' => 'zero', 'label' => 'Zero', 'type' => 'number', 'default' => 0],
        ]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'checks', [
            [
                'key' => 'persuade', 'label' => 'Persuade',
                'pool' => ['count' => 5, 'criticals' => ['bonus' => 2], 'hunger' => ['count' => ['formula' => '{hunger}']]] + $pool,
                'difficulty' => ['label' => 'Difficulty', 'default' => 3, 'min' => 1, 'max' => 10],
                self::BANDS => $bands,
                'push' => ['label' => 'Spend Willpower', 'maxDice' => 2],
            ],
            ['key' => 'frenzy', 'label' => 'Frenzy', 'pool' => ['count' => 3, 'hunger' => ['count' => ['formula' => '{hunger} / {zero}']]] + $pool, self::BANDS => [['key' => 'fail', 'label' => 'Failure', 'upTo' => 0], ['key' => 'win', 'label' => 'Win']]],
            ['key' => 'uncapped', 'label' => 'Uncapped', 'pool' => ['count' => 4, 'hunger' => ['count' => 1]] + $pool, self::BANDS => [['key' => 'fail', 'label' => 'Failure', 'upTo' => 0], ['key' => 'win', 'label' => 'Win']], 'push' => ['label' => 'Push']],
        ]);
        $bus = $container->get(CommandBus::class);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9302', $content, false));
        $bus->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignTellsTheCapOfEachPush(): void
    {
        [$id] = $this->campaignInAScene();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($campaign);
        self::assertIsArray($campaign['checks'] ?? null);
        $pushes = array_column($campaign['checks'], 'push', 'key');
        self::assertSame(['label' => 'Spend Willpower', 'picks' => [], 'maxDice' => 2], $pushes['persuade']);
        self::assertSame(['label' => 'Push', 'picks' => [], 'maxDice' => null], $pushes['uncapped']);
        self::assertArrayHasKey('frenzy', $pushes);
        self::assertNull($pushes['frenzy']);
    }

    #[Test]
    public function aHungerRollJournalsTheGroupOfEachDieTheBonusAndTheMessyCritical(): void
    {
        [$id, $vi] = $this->campaignInAScene();

        // Three regular dice (10, 2, 2), then the two Hunger dice (10, 3): a pair with a Hunger die.
        $entry = $this->roll($id, $vi, 'persuade', [10, 2, 2, 10, 3]);

        $content = $entry['content'];
        self::assertSame(['win', 4, 4, 'messyCritical'], [$content['bandKey'], $content['total'], $content['diceTotal'], $content['special']]);
        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], $this->column($content['pool']['dice'], 'group'));
        self::assertSame([2, 2, 'messyCritical', null], [$content['pool']['successes'], $content['pool']['criticalsBonus'], $content['pool']['special'], $content['pool']['push']]);
        self::assertSame($entry, $this->journal($id)[0], 'What was stored reads back as the same entry.');
    }

    #[Test]
    public function aFailedRollWithAHungerOneIsABestialFailure(): void
    {
        [$id, $vi] = $this->campaignInAScene();

        $entry = $this->roll($id, $vi, 'persuade', [2, 3, 2, 1, 3]);

        self::assertSame(['fail', 0, 'bestialFailure'], [$entry['content']['bandKey'], $entry['content']['total'], $entry['content']['special']]);
    }

    #[Test]
    public function aCapPushRerollsTheFirstFailedRegularDiceNeverHungerDiceAndRecomputesTheSpecialResult(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'persuade', [2, 3, 2, 1, 3]);

        // The cap is 2: only the first two failed regular dice are re-rolled, in order.
        $this->random->script(10, 10);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(200);
        $pushed = $this->entryJson();
        $content = $pushed['content'];
        self::assertSame(['win', 4, 'critical'], [$content['bandKey'], $content['total'], $content['special']]);
        $push = $content['pool']['push'];
        self::assertIsArray($push);
        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], $this->column($push['dice'], 'group'));
        self::assertSame([10, 10, 2, 1, 3], $this->column($push['dice'], 'face'));
        self::assertSame([true, true, false, false, false], $this->column($push['dice'], 'rerolled'));
        self::assertSame('critical', $push['special']);
        self::assertSame('bestialFailure', $content['pool']['special'], 'The first roll keeps its own special result.');
        self::assertSame($pushed, $this->journal($id)[0]);
    }

    #[Test]
    public function aPushWithoutACapRerollsEveryFailedRegularDie(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'uncapped', [1, 2, 3, 1]);

        $this->random->script(7, 7, 7);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(200);
        $push = $this->entryJson()['content']['pool']['push'];
        self::assertIsArray($push);
        self::assertSame([7, 7, 7, 1], $this->column($push['dice'], 'face'));
        self::assertSame([true, true, true, false], $this->column($push['dice'], 'rerolled'));
    }

    #[Test]
    public function aRollWhoseOnlyFailuresAreHungerDiceCannotBePushed(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'uncapped', [7, 7, 7, 1]);
        $before = $this->journal($id);

        $this->random->script(7);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No regular die of the roll failed: there is nothing to re-roll.'], $this->json());
        self::assertSame($before, $this->journal($id));
    }

    #[Test]
    public function aHungerFormulaThatCannotBeEvaluatedRefusesTheCheckAndRecordsNothing(): void
    {
        [$id, $vi] = $this->campaignInAScene();

        $this->random->script(5, 5, 5);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'frenzy', 'character' => $vi]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Check "frenzy" cannot be performed', $this->string($this->json()['error'] ?? null));
        self::assertSame([], $this->journal($id));
    }

    #[Test]
    public function anEntryStoredBeforeHungerDiceReadsWithNoSpecialResultAndAPoolBonusOfZero(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'uncapped', [7, 7, 7, 7]);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $stored = $connection->fetchOne('SELECT content FROM play_journal_entry WHERE id = ?', [$entry['id']]);
        self::assertIsString($stored);
        $legacy = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($legacy);
        self::assertIsArray($legacy['pool']);
        // Rewrites the row as an entry stored before this feature: pool dice only, no bonus, no special.
        unset($legacy['pool']['criticalsBonus'], $legacy['pool']['special']);
        /** @var list<array<string, mixed>> $dice */
        $dice = $legacy['pool']['dice'];
        $legacy['pool']['dice'] = array_map(static fn (array $die): array => ['group' => 'pool'] + $die, $dice);
        $connection->executeStatement('UPDATE play_journal_entry SET content = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $entry['id']]);

        $read = $this->journal($id)[0]['content'];

        self::assertSame([null, 0, null], [$read['special'], $read['pool']['criticalsBonus'], $read['pool']['special']]);
        self::assertSame(['pool', 'pool', 'pool', 'pool'], $this->column($read['pool']['dice'], 'group'));
    }

    /**
     * @return array{string, string} the campaign in session 1, scene 1, and Vi (Hunger 2) in it
     */
    private function campaignInAScene(): array
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The night', 'gameSystemKey' => 'night']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->entryJson()['id'];
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At court']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'values' => []]);
        self::assertResponseStatusCodeSame(201);

        return [$id, $this->entryJson()['id']];
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

    private function push(string $campaignId, string $entryId): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $campaignId, $entryId), []);
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
     * @return Entry
     */
    private function entryJson(): array
    {
        /** @var Entry $entry */
        $entry = $this->json();

        return $entry;
    }

    /**
     * @return list<mixed> one key of every die
     */
    private function column(mixed $dice, string $key): array
    {
        self::assertIsArray($dice);

        return array_column($dice, $key);
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
