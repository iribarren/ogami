<?php

declare(strict_types=1);

namespace App\Tests\Integration\Randomness\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Infrastructure\Http\DiceGroupResponse;
use App\Randomness\Infrastructure\Http\RollController;
use App\Randomness\Infrastructure\Http\RolledDieResponse;
use App\Randomness\Infrastructure\Http\RollResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rolling dice over `POST /api/rolls`. The random number generator is
 * scripted so totals are deterministic.
 */
#[CoversClass(RollController::class)]
#[CoversClass(RollResponse::class)]
#[CoversClass(DiceGroupResponse::class)]
#[CoversClass(RolledDieResponse::class)]
final class RollApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep one container across requests, so the scripted generator set
        // below is the one the roll request uses.
        $this->client->disableReboot();
    }

    #[Test]
    public function aSignedInUserRollsDiceAndSeesEveryDieIncludingDroppedOnes(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(3, 5, 2, 6);

        $this->roll(['expression' => '4d6kh3']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "expression": "4d6kh3",
                    "total": 14,
                    "groups": [
                        {
                            "notation": "4d6kh3",
                            "sides": 6,
                            "dice": [
                                {"value": 3, "kept": true},
                                {"value": 5, "kept": true},
                                {"value": 2, "kept": false},
                                {"value": 6, "kept": true}
                            ],
                            "subtotal": 14
                        }
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function aModifierCountsTowardsTheTotalButIsNotAGroup(): void
    {
        $this->signIn(['GAME_MANAGER']);
        $this->script(3, 5);

        $this->roll(['expression' => ' 2D6 + 1 ']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {
                    "expression": "2d6+1",
                    "total": 9,
                    "groups": [
                        {
                            "notation": "2d6",
                            "sides": 6,
                            "dice": [{"value": 3, "kept": true}, {"value": 5, "kept": true}],
                            "subtotal": 8
                        }
                    ]
                }
                JSON,
            $this->content(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidExpressions(): iterable
    {
        yield 'missing sides' => ['2d', 'Unexpected end of the dice expression; expected the number of sides or "%".'];
        yield 'keeping more dice than rolled' => ['4d6kh5', '"kh" keeps between 1 and 4 dice, 5 given.'];
        yield 'division by zero' => ['1d6/0', '"1d6/0" divides by zero.'];
        yield 'non-ASCII character' => ['2d6+é', 'Unexpected "é" at position 5; a dice expression only contains numbers, dice ("d", "%"), selectors ("kh", "kl", "dh", "dl", "k"), "+", "-", "*", "/" and parentheses.'];
    }

    #[Test]
    #[DataProvider('invalidExpressions')]
    public function anInvalidExpressionIsUnprocessable(string $expression, string $error): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(4);

        $this->roll(['expression' => $expression]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['error' => $error], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'invalid JSON' => ['{"expression": '];
        yield 'not an object' => ['"2d6"'];
        yield 'missing expression' => ['{}'];
        yield 'non-string expression' => ['{"expression": 6}'];
        yield 'null expression' => ['{"expression": null}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/rolls', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with a string "expression", such as {"expression": "2d6+1"}.'], $this->json());
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/rolls', ['expression' => '2d6']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the dice expression as JSON.'], $this->json());
    }

    #[Test]
    public function rollingNeedsASession(): void
    {
        $this->roll(['expression' => '2d6']);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonStringEqualsJsonString('{"error":"Authentication required."}', $this->content());
    }

    #[Test]
    public function rollingTakesAPost(): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('GET', '/api/rolls');

        self::assertResponseStatusCodeSame(405);
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
    private function roll(array $body): void
    {
        $this->client->jsonRequest('POST', '/api/rolls', $body);
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
