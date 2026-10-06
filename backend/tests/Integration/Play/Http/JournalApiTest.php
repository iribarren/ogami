<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Infrastructure\Http\JournalController;
use App\Play\Infrastructure\Http\JournalEntryResponse;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The journal of a campaign over the Play API: notes, rolls and oracle answers recorded in the
 * current scene, rolled on the server against the campaign's pinned release.
 */
#[CoversClass(JournalController::class)]
#[CoversClass(JournalEntryResponse::class)]
final class JournalApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80';
    private const string UNKNOWN_CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';

    private KernelBrowser $client;
    private FixedClock $clock;
    private UuidSequenceJournalEntryIdGenerator $entryIds;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container across requests, so the doubles below are the ones the requests use.
        $this->client->disableReboot();
        $this->clock = new FixedClock('2026-10-06T09:00:00+00:00');
        $container = self::getContainer();
        $container->set(Clock::class, $this->clock);
        // Entry ids 0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001, …8002…, in recording order.
        $this->entryIds = new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX);
        $container->set(JournalEntryIdGenerator::class, $this->entryIds);
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
    }

    #[Test]
    public function aSoloPlayerRecordsANote(): void
    {
        $id = $this->campaignInAScene();
        $this->clock->moveTo('2026-10-06T09:30:00.123456+00:00');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => '  The gate is open. ']);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonStringEqualsJsonString($this->encoded([
            'id' => $this->entryId(1),
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-06T09:30:00+00:00',
            'kind' => 'note',
            'content' => ['kind' => 'note', 'text' => 'The gate is open.'],
        ]), $this->content());
    }

    #[Test]
    public function aSoloPlayerRecordsARollMadeOnTheServer(): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(6, 1, 4);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/rolls', $id), ['expression' => '2d6kh1 + d4']);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonStringEqualsJsonString($this->encoded([
            'id' => $this->entryId(1),
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-06T09:20:00+00:00',
            'kind' => 'roll',
            'content' => [
                'kind' => 'roll',
                'expression' => '2d6kh1+1d4',
                'total' => 10,
                'groups' => [
                    ['notation' => '2d6kh1', 'sides' => 6, 'dice' => [['value' => 6, 'kept' => true], ['value' => 1, 'kept' => false]], 'subtotal' => 6],
                    ['notation' => '1d4', 'sides' => 4, 'dice' => [['value' => 4, 'kept' => true]], 'subtotal' => 4],
                ],
            ],
        ]), $this->content());
    }

    #[Test]
    public function aSoloPlayerRecordsAnOracleTableResultWithItsNestedTable(): void
    {
        $id = $this->campaignInAScene();
        // Weather 1d6 shows 6 (Storm, nests storm-kind); storm-kind (weights 2 + 1) shows 3 (Hail).
        $this->scriptRandomNumbers(6, 3);

        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/oracle-tables/weather', $id));

        self::assertResponseStatusCodeSame(201);
        self::assertJsonStringEqualsJsonString($this->encoded([
            'id' => $this->entryId(1),
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-06T09:20:00+00:00',
            'kind' => 'oracle-table',
            'content' => [
                'kind' => 'oracle-table',
                'oracleKey' => 'weather',
                'oracleName' => 'Weather',
                'steps' => [
                    ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 6, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind'],
                    ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d3', 'total' => 3, 'text' => 'Hail', 'nestedTableKey' => null],
                ],
            ],
        ]), $this->content());
    }

    #[Test]
    public function aSoloPlayerRecordsALikelihoodAnswerToTheirQuestion(): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(30);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), [
            'likelihood' => 'even',
            'chaosFactor' => 5,
            'question' => '  Is it guarded? ',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonStringEqualsJsonString($this->encoded([
            'id' => $this->entryId(1),
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-06T09:20:00+00:00',
            'kind' => 'likelihood',
            'content' => [
                'kind' => 'likelihood',
                'oracleKey' => 'fate',
                'oracleName' => 'Fate question',
                'question' => 'Is it guarded?',
                'answer' => 'yes',
                'roll' => 30,
                'sides' => 100,
                'effectiveTarget' => 50,
                'likelihood' => 'even',
                'likelihoodLabel' => '50/50',
                'chaosFactor' => 5,
            ],
        ]), $this->content());
    }

    #[Test]
    public function aLikelihoodAnswerWithoutChaosFactorOrQuestionUsesTheNeutralFactor(): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(70);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), ['likelihood' => 'likely']);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertSame(
            [
                'kind' => 'likelihood',
                'oracleKey' => 'fate',
                'oracleName' => 'Fate question',
                'question' => null,
                'answer' => 'no',
                'roll' => 70,
                'sides' => 100,
                'effectiveTarget' => 65,
                'likelihood' => 'likely',
                'likelihoodLabel' => 'Likely',
                'chaosFactor' => 5,
            ],
            $content,
        );
    }

    #[Test]
    public function theJournalListsEveryEntryInOrderWithItsSessionAndScene(): void
    {
        $id = $this->campaignInAScene();
        $this->clock->moveTo('2026-10-06T09:30:00+00:00');
        $this->record($id, 'notes', ['text' => 'The gate is open.']);
        $this->scriptRandomNumbers(2);
        $this->clock->moveTo('2026-10-06T09:40:00+00:00');
        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/oracle-tables/weather', $id));
        self::assertResponseStatusCodeSame(201);

        $this->clock->moveTo('2026-10-06T10:00:00+00:00');
        $this->startScene($id, 'In the mine');
        $this->scriptRandomNumbers(5);
        $this->clock->moveTo('2026-10-06T10:10:00+00:00');
        $this->record($id, 'rolls', ['expression' => 'd20']);

        $this->clock->moveTo('2026-10-07T18:00:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->startScene($id, 'Back in town');
        $this->clock->moveTo('2026-10-07T18:05:00+00:00');
        $this->scriptRandomNumbers(95);
        $this->record($id, 'likelihood-oracles/fate', ['likelihood' => 'unlikely', 'chaosFactor' => 5, 'question' => 'Is the inn open?']);

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString($this->encoded([
            [
                'id' => $this->entryId(1),
                'sessionNumber' => 1,
                'sceneNumber' => 1,
                'recordedAt' => '2026-10-06T09:30:00+00:00',
                'kind' => 'note',
                'content' => ['kind' => 'note', 'text' => 'The gate is open.'],
            ],
            [
                'id' => $this->entryId(2),
                'sessionNumber' => 1,
                'sceneNumber' => 1,
                'recordedAt' => '2026-10-06T09:40:00+00:00',
                'kind' => 'oracle-table',
                'content' => [
                    'kind' => 'oracle-table',
                    'oracleKey' => 'weather',
                    'oracleName' => 'Weather',
                    'steps' => [['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 2, 'text' => 'Clear', 'nestedTableKey' => null]],
                ],
            ],
            [
                'id' => $this->entryId(3),
                'sessionNumber' => 1,
                'sceneNumber' => 2,
                'recordedAt' => '2026-10-06T10:10:00+00:00',
                'kind' => 'roll',
                'content' => [
                    'kind' => 'roll',
                    'expression' => '1d20',
                    'total' => 5,
                    'groups' => [['notation' => '1d20', 'sides' => 20, 'dice' => [['value' => 5, 'kept' => true]], 'subtotal' => 5]],
                ],
            ],
            [
                'id' => $this->entryId(4),
                'sessionNumber' => 2,
                'sceneNumber' => 1,
                'recordedAt' => '2026-10-07T18:05:00+00:00',
                'kind' => 'likelihood',
                'content' => [
                    'kind' => 'likelihood',
                    'oracleKey' => 'fate',
                    'oracleName' => 'Fate question',
                    'question' => 'Is the inn open?',
                    'answer' => 'exceptional_no',
                    'roll' => 95,
                    'sides' => 100,
                    'effectiveTarget' => 35,
                    'likelihood' => 'unlikely',
                    'likelihoodLabel' => 'Unlikely',
                    'chaosFactor' => 5,
                ],
            ],
        ]), $this->content());
    }

    #[Test]
    public function aNewCampaignHasAnEmptyJournal(): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));

        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->content());
    }

    #[Test]
    public function oraclesAreAskedInThePinnedReleaseNotInANewerOne(): void
    {
        $id = $this->campaignInAScene();
        // The newer release has no likelihood oracle at all.
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6002', withoutLikelihoodOracles: true);
        $this->scriptRandomNumbers(30);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), ['likelihood' => 'even']);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(['fate', 'Fate question', 'yes'], [$content['oracleKey'] ?? null, $content['oracleName'] ?? null, $content['answer'] ?? null]);
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>}>
     */
    public static function recordings(): iterable
    {
        yield 'note' => ['notes', ['text' => 'The gate is open.']];
        yield 'roll' => ['rolls', ['expression' => '2d6']];
        yield 'oracle table' => ['oracle-tables/weather', null];
        yield 'likelihood oracle' => ['likelihood-oracles/fate', ['likelihood' => 'even']];
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('recordings')]
    public function anEntryNeedsAScene(string $path, ?array $body): void
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->scriptRandomNumbers(3, 4);

        $this->post($id, $path, $body);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a scene before recording a journal entry.'], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    #[Test]
    public function anEntryIdAlreadyTakenIsAConflict(): void
    {
        $id = $this->campaignInAScene();
        $this->entryIds->repeat('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8099');
        $this->record($id, 'notes', ['text' => 'First.']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => 'Second.']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A journal entry with id "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8099" already exists.'], $this->json());
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        $journal = $this->json();
        self::assertCount(1, $journal);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownOracles(): iterable
    {
        yield 'oracle table' => ['oracle-tables/no-such-table', 'GameSystem "example-journal" v1 has no oracle table "no-such-table".'];
        yield 'likelihood oracle' => ['likelihood-oracles/no-such-oracle', 'GameSystem "example-journal" v1 has no likelihood oracle "no-such-oracle".'];
        // A table key is not a likelihood oracle key, and the reverse.
        yield 'table asked as likelihood' => ['likelihood-oracles/weather', 'GameSystem "example-journal" v1 has no likelihood oracle "weather".'];
        yield 'likelihood rolled as table' => ['oracle-tables/fate', 'GameSystem "example-journal" v1 has no oracle table "fate".'];
    }

    #[Test]
    #[DataProvider('unknownOracles')]
    public function anOracleNotInThePinnedReleaseIsNotFound(string $path, string $error): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(30);

        $this->post($id, $path, ['likelihood' => 'even']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => $error], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function unprocessableRecordings(): iterable
    {
        yield 'blank note' => ['notes', ['text' => '   '], 'A note must not be blank.'];
        yield 'note too long' => ['notes', ['text' => str_repeat('a', NoteContent::MAX_LENGTH + 1)], 'A note must be at most 10000 characters, got 10001.'];
        yield 'invalid dice expression' => ['rolls', ['expression' => '2x6'], 'Unexpected "x" at position 2; a dice expression only contains numbers, dice ("d", "%"), selectors ("kh", "kl", "dh", "dl", "k"), "+", "-", "*", "/" and parentheses.'];
        yield 'unknown likelihood level' => ['likelihood-oracles/fate', ['likelihood' => 'certain'], 'There is no likelihood level "certain"; the levels are "unlikely", "even", "likely".'];
        yield 'chaos factor out of range' => ['likelihood-oracles/fate', ['likelihood' => 'even', 'chaosFactor' => 10], 'The chaos factor is between 1 and 9, 10 given.'];
        yield 'question too long' => ['likelihood-oracles/fate', ['likelihood' => 'even', 'question' => str_repeat('a', LikelihoodContent::MAX_QUESTION_LENGTH + 1)], 'A question must be at most 500 characters, got 501.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessableRecordings')]
    public function anInvalidRecordingIsUnprocessableAndRecordsNothing(string $path, array $body, string $error): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(30);

        $this->post($id, $path, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedBodies(): iterable
    {
        $note = 'Send a JSON object with a string "text", such as {"text": "The gate is open."}.';
        $roll = 'Send a JSON object with a string "expression", such as {"expression": "2d6+1"}.';
        $likelihood = 'Send a JSON object with a string "likelihood", and optionally an integer "chaosFactor" and a string "question", such as {"likelihood": "likely", "chaosFactor": 5, "question": "Is the door locked?"}.';

        yield 'note: invalid JSON' => ['notes', '{"text": ', $note];
        yield 'note: not an object' => ['notes', '"The gate is open."', $note];
        yield 'note: missing text' => ['notes', '{}', $note];
        yield 'note: non-string text' => ['notes', '{"text": 3}', $note];
        yield 'roll: invalid JSON' => ['rolls', '{"expression"', $roll];
        yield 'roll: missing expression' => ['rolls', '{"text": "2d6"}', $roll];
        yield 'roll: non-string expression' => ['rolls', '{"expression": 6}', $roll];
        yield 'likelihood: invalid JSON' => ['likelihood-oracles/fate', '{', $likelihood];
        yield 'likelihood: missing likelihood' => ['likelihood-oracles/fate', '{"chaosFactor": 5}', $likelihood];
        yield 'likelihood: non-integer chaos factor' => ['likelihood-oracles/fate', '{"likelihood": "even", "chaosFactor": "5"}', $likelihood];
        yield 'likelihood: fractional chaos factor' => ['likelihood-oracles/fate', '{"likelihood": "even", "chaosFactor": 5.5}', $likelihood];
        yield 'likelihood: non-string question' => ['likelihood-oracles/fate', '{"likelihood": "even", "question": 7}', $likelihood];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $path, string $body, string $error): void
    {
        $id = $this->campaignInAScene();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/%s', $id, $path), server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => $error], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    #[Test]
    public function nullOptionalLikelihoodFieldsAreAccepted(): void
    {
        $id = $this->campaignInAScene();
        $this->scriptRandomNumbers(30);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $id), ['likelihood' => 'even', 'chaosFactor' => null, 'question' => null]);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(5, $content['chaosFactor'] ?? null);
        self::assertArrayHasKey('question', $content);
        self::assertNull($content['question']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiedRecordings(): iterable
    {
        yield 'note' => ['notes', 'Send the note as JSON.'];
        yield 'roll' => ['rolls', 'Send the roll as JSON.'];
        yield 'likelihood oracle' => ['likelihood-oracles/fate', 'Send the question as JSON.'];
    }

    #[Test]
    #[DataProvider('bodiedRecordings')]
    public function aFormEncodedBodyIsRejected(string $path, string $error): void
    {
        $id = $this->campaignInAScene();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/%s', $id, $path), ['text' => 'The gate is open.']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => $error], $this->json());
    }

    /**
     * @return iterable<string, array{string, string, ?array<string, mixed>}>
     */
    public static function journalEndpoints(): iterable
    {
        yield 'get journal' => ['GET', '/api/campaigns/%s/journal', null];
        yield 'record note' => ['POST', '/api/campaigns/%s/journal/notes', ['text' => 'The gate is open.']];
        yield 'record roll' => ['POST', '/api/campaigns/%s/journal/rolls', ['expression' => '2d6']];
        yield 'record oracle table result' => ['POST', '/api/campaigns/%s/journal/oracle-tables/weather', null];
        yield 'record likelihood answer' => ['POST', '/api/campaigns/%s/journal/likelihood-oracles/fate', ['likelihood' => 'even']];
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('journalEndpoints')]
    public function anotherPlayersCampaignIsNotFound(string $method, string $path, ?array $body): void
    {
        $id = $this->campaignInAScene('bob@example.com');
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $this->scriptRandomNumbers(3, 4);

        $this->client->jsonRequest($method, \sprintf($path, $id), $body ?? []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
        $this->signIn('bob@example.com', ['SOLO_PLAYER'], create: false);
        $this->assertJournalIsEmpty($id);
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('journalEndpoints')]
    public function anUnknownOrMalformedCampaignIdIsNotFound(string $method, string $path, ?array $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([self::UNKNOWN_CAMPAIGN, 'not-a-uuid'] as $id) {
            $this->client->jsonRequest($method, \sprintf($path, $id), $body ?? []);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $id)], $this->json());
        }
    }

    /**
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('journalEndpoints')]
    public function theJournalNeedsASession(string $method, string $path, ?array $body): void
    {
        $this->client->jsonRequest($method, \sprintf($path, self::UNKNOWN_CAMPAIGN), $body ?? []);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'Authentication required.'], $this->json());
    }

    /**
     * Every role but SOLO_PLAYER is denied by the same access rule (CampaignApiTest checks each
     * role); one of them per endpoint is enough here.
     *
     * @param ?array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('journalEndpoints')]
    public function theJournalIsForSoloPlayersOnly(string $method, string $path, ?array $body): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);

        $this->client->jsonRequest($method, \sprintf($path, self::UNKNOWN_CAMPAIGN), $body ?? []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'Access denied.'], $this->json());
    }

    private function entryId(int $number): string
    {
        return $this->entryIds->idNumber($number);
    }

    /**
     * Publishes the example release, signs the player in and creates a campaign in session 1,
     * scene 1 (started at 09:10); the clock is left at 09:20.
     */
    private function campaignInAScene(string $email = 'ada@example.com'): string
    {
        $this->publish('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001');
        $this->signIn($email, ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->clock->moveTo('2026-10-06T09:05:00+00:00');
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->clock->moveTo('2026-10-06T09:10:00+00:00');
        $this->startScene($id, 'At the gate');
        $this->clock->moveTo('2026-10-06T09:20:00+00:00');

        return $id;
    }

    private function startScene(string $campaignId, string $title): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $campaignId), ['title' => $title]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function record(string $campaignId, string $path, array $body): void
    {
        $this->post($campaignId, $path, $body);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Posts to a journal endpoint; the oracle table endpoint takes no body.
     *
     * @param ?array<string, mixed> $body
     */
    private function post(string $campaignId, string $path, ?array $body): void
    {
        $uri = \sprintf('/api/campaigns/%s/journal/%s', $campaignId, $path);
        if (str_starts_with($path, 'oracle-tables/') || null === $body) {
            $this->client->request('POST', $uri);

            return;
        }

        $this->client->jsonRequest('POST', $uri, $body);
    }

    private function assertJournalIsEmpty(string $campaignId): void
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $campaignId));
        self::assertResponseIsSuccessful();
        self::assertSame('[]', $this->content());
    }

    private function scriptRandomNumbers(int ...$numbers): void
    {
        $this->random->script(...$numbers);
    }

    private function publish(string $releaseId, bool $withoutLikelihoodOracles = false): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        if ($withoutLikelihoodOracles) {
            /** @var array<string, mixed> $oracles */
            $oracles = $content['oracles'];
            $oracles['likelihood'] = [];
            $content['oracles'] = $oracles;
        }

        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease($releaseId, $content, false));
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The lost mine', 'gameSystemKey' => 'example-journal']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles, bool $create = true): void
    {
        if ($create) {
            $container = self::getContainer();
            $id = $container->get(UserIdGenerator::class)->generate()->toString();
            $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));
        }

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<mixed> $value
     */
    private function encoded(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Impure: it decodes the latest response, so PHPStan must not reuse a type narrowed by an
     * assertion on an earlier one.
     *
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function json(): array
    {
        $decoded = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
