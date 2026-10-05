<?php

declare(strict_types=1);

namespace App\Tests\Integration\Randomness\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Infrastructure\Http\OracleTableController;
use App\Randomness\Infrastructure\Http\OracleTableResultResponse;
use App\Randomness\Infrastructure\Http\OracleTableStepResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rolling on oracle tables over `POST /api/oracle-table-results`. The random
 * number generator is scripted so the selected entries are deterministic.
 */
#[CoversClass(OracleTableController::class)]
#[CoversClass(OracleTableResultResponse::class)]
#[CoversClass(OracleTableStepResponse::class)]
final class OracleTableApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private const string MALFORMED_BODY = 'Send a JSON object with a "tables" list and a string "table", such as {"tables": [{"key": "weather", …}], "table": "weather"}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep one container across requests, so the scripted generator set
        // below is the one the request uses.
        $this->client->disableReboot();
    }

    #[Test]
    public function aRangedTableSelectsTheEntryCoveringTheRoll(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(2);

        $this->resolve(['tables' => self::tables(), 'table' => 'weather']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "table": "weather",
                    "steps": [
                        {"tableKey": "weather", "tableName": "Weather", "dice": "1d6", "total": 2, "text": "Clear", "nestedTableKey": null}
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function aWeightedTableRollsItsTotalWeight(): void
    {
        $this->signIn(['GAME_MANAGER']);
        $this->script(3);

        $this->resolve(['tables' => self::tables(), 'table' => 'storm-kind']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "table": "storm-kind",
                    "steps": [
                        {"tableKey": "storm-kind", "tableName": "Storm kind", "dice": "1d3", "total": 3, "text": "Hail", "nestedTableKey": null}
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function aNestedEntryAlsoRollsOnTheTableItNames(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(6, 1);

        $this->resolve(['tables' => self::tables(), 'table' => 'weather']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "table": "weather",
                    "steps": [
                        {"tableKey": "weather", "tableName": "Weather", "dice": "1d6", "total": 6, "text": "Storm", "nestedTableKey": "storm-kind"},
                        {"tableKey": "storm-kind", "tableName": "Storm kind", "dice": "1d3", "total": 1, "text": "Thunder", "nestedTableKey": null}
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function explicitNullOptionalFieldsCountAsAbsent(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(6, 3);

        $this->resolve(['tables' => [
            ['key' => 'weather', 'name' => 'Weather', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 3, 'weight' => null, 'text' => 'Clear', 'table' => null],
                ['min' => 4, 'max' => 6, 'weight' => null, 'text' => null, 'table' => 'storm-kind'],
            ]],
            ['key' => 'storm-kind', 'name' => 'Storm kind', 'dice' => null, 'entries' => [
                ['min' => null, 'max' => null, 'weight' => 2, 'text' => 'Thunder', 'table' => null],
                ['min' => null, 'max' => null, 'weight' => null, 'text' => 'Hail', 'table' => null],
            ]],
        ], 'table' => 'weather']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "table": "weather",
                    "steps": [
                        {"tableKey": "weather", "tableName": "Weather", "dice": "1d6", "total": 6, "text": "", "nestedTableKey": "storm-kind"},
                        {"tableKey": "storm-kind", "tableName": "Storm kind", "dice": "1d3", "total": 3, "text": "Hail", "nestedTableKey": null}
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    /**
     * @return iterable<string, array{list<mixed>, string, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'overlapping ranges' => [
            [['key' => 'a', 'name' => 'A', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 4, 'text' => 'One'],
                ['min' => 3, 'max' => 6, 'text' => 'Two'],
            ]]],
            'a',
            'Entries 1 (1 to 4) and 2 (3 to 6) of oracle table "a" overlap.',
        ];
        yield 'mixed entries' => [
            [['key' => 'a', 'name' => 'A', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 3, 'text' => 'One'],
                ['weight' => 2, 'text' => 'Two'],
            ]]],
            'a',
            'Entry 2 of oracle table "a" has no range, but the table has dice; a table\'s entries are either all ranged or all weighted.',
        ];
        yield 'unknown nested table' => [
            [['key' => 'a', 'name' => 'A', 'entries' => [['table' => 'b']]]],
            'a',
            'Entry 1 of oracle table "a" nests "b", but there is no oracle table "b" in the table set.',
        ];
        yield 'cycle' => [
            [
                ['key' => 'a', 'name' => 'A', 'entries' => [['table' => 'b']]],
                ['key' => 'b', 'name' => 'B', 'entries' => [['table' => 'a']]],
            ],
            'a',
            'Oracle tables nest in a cycle: "a" > "b" > "a".',
        ];
        yield 'no tables' => [[], 'a', 'A table set has between 1 and 50 oracle tables, 0 given.'];
        yield 'entry field of the wrong type' => [
            [['key' => 'a', 'name' => 'A', 'entries' => [['text' => 'One', 'weight' => '2']]]],
            'a',
            'The "weight" of entry 1 of oracle table "a" is an integer.',
        ];
        yield 'unknown table asked' => [self::tables(), 'fog', 'There is no oracle table "fog" in the table set.'];
        yield 'uncovered roll' => [
            [['key' => 'a', 'name' => 'A', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 3, 'text' => 'Low']]]],
            'a',
            'Rolled 5 on table "a", but no entry covers it.',
        ];
    }

    /**
     * @param list<mixed> $tables
     */
    #[Test]
    #[DataProvider('invalidRequests')]
    public function anInvalidTableOrAskIsUnprocessable(array $tables, string $table, string $error): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(5);

        $this->resolve(['tables' => $tables, 'table' => $table]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['error' => $error], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'invalid JSON' => ['{"tables": '];
        yield 'not an object' => ['"weather"'];
        yield 'missing tables' => ['{"table": "weather"}'];
        yield 'non-list tables' => ['{"tables": "weather", "table": "weather"}'];
        yield 'tables object instead of a list' => ['{"tables": {"weather": {"key": "weather", "name": "Weather", "entries": [{"text": "Clear"}]}}, "table": "weather"}'];
        yield 'tables list with gaps' => ['{"tables": {"1": {"key": "weather", "name": "Weather", "entries": [{"text": "Clear"}]}}, "table": "weather"}'];
        yield 'missing table' => ['{"tables": []}'];
        yield 'non-string table' => ['{"tables": [], "table": 1}'];
        yield 'null table' => ['{"tables": [], "table": null}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/oracle-table-results', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => self::MALFORMED_BODY], $this->json());
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/oracle-table-results', ['table' => 'weather']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the oracle tables as JSON.'], $this->json());
    }

    #[Test]
    public function rollingOnATableNeedsASession(): void
    {
        $this->resolve(['tables' => self::tables(), 'table' => 'weather']);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonStringEqualsJsonString('{"error":"Authentication required."}', $this->content());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function tables(): array
    {
        return [
            ['key' => 'weather', 'name' => 'Weather', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 3, 'text' => 'Clear'],
                ['min' => 4, 'max' => 5, 'text' => 'Rain'],
                ['min' => 6, 'max' => 6, 'text' => 'Storm', 'table' => 'storm-kind'],
            ]],
            ['key' => 'storm-kind', 'name' => 'Storm kind', 'entries' => [
                ['weight' => 2, 'text' => 'Thunder'],
                ['text' => 'Hail'],
            ]],
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, 'ada@example.com', self::PASSWORD, $roles));

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    private function script(int ...$numbers): void
    {
        self::getContainer()->set(RandomNumberGenerator::class, new ScriptedRandomNumberGenerator(...$numbers));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolve(array $body): void
    {
        $this->client->jsonRequest('POST', '/api/oracle-table-results', $body);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
