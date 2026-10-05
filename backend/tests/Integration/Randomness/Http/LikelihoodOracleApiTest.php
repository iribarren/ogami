<?php

declare(strict_types=1);

namespace App\Tests\Integration\Randomness\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Randomness\Infrastructure\Http\LikelihoodAnswerResponse;
use App\Randomness\Infrastructure\Http\LikelihoodOracleController;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Asking a likelihood oracle over `POST /api/likelihood-answers`. The random
 * number generator is scripted so answers are deterministic.
 */
#[CoversClass(LikelihoodOracleController::class)]
#[CoversClass(LikelihoodAnswerResponse::class)]
final class LikelihoodOracleApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private const string MALFORMED_BODY = 'Send a JSON object with an "oracle" object, a string "likelihood" and an optional integer "chaosFactor", such as {"oracle": {"sides": 100, …}, "likelihood": "likely", "chaosFactor": 5}.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Keep one container across requests, so the scripted generator set
        // below is the one the request uses.
        $this->client->disableReboot();
    }

    /**
     * Likely is 65 at the neutral chaos factor 5: exceptional yes up to 13,
     * exceptional no above 100 − floor(35 × 20 / 100) = 93.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function answers(): iterable
    {
        yield 'exceptional yes' => [13, 'exceptional_yes'];
        yield 'yes' => [65, 'yes'];
        yield 'no' => [93, 'no'];
        yield 'exceptional no' => [94, 'exceptional_no'];
    }

    #[Test]
    #[DataProvider('answers')]
    public function aSignedInUserAsksWithALikelihoodAndTheNeutralChaosFactor(int $roll, string $answer): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script($roll);

        $this->ask(['oracle' => self::oracle(), 'likelihood' => 'likely']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'answer' => $answer,
                'roll' => $roll,
                'sides' => 100,
                'effectiveTarget' => 65,
                'likelihood' => 'likely',
                'likelihoodLabel' => 'Likely',
                'chaosFactor' => 5,
            ],
            $this->json(),
        );
    }

    #[Test]
    public function theChaosFactorShiftsTheTarget(): void
    {
        $this->signIn(['GAME_MANAGER']);
        $this->script(80);

        $this->ask(['oracle' => self::oracle(), 'likelihood' => 'likely', 'chaosFactor' => 9]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {"answer": "yes", "roll": 80, "sides": 100, "effectiveTarget": 85, "likelihood": "likely", "likelihoodLabel": "Likely", "chaosFactor": 9}
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function theShiftedTargetIsClampedToTheSides(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(100);

        $this->ask(['oracle' => self::oracle(), 'likelihood' => 'nearly-certain', 'chaosFactor' => 9]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {"answer": "yes", "roll": 100, "sides": 100, "effectiveTarget": 100, "likelihood": "nearly-certain", "likelihoodLabel": "Nearly certain", "chaosFactor": 9}
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function anOracleWithoutChaosAnswersWithANullChaosFactor(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(4);

        $this->ask([
            'oracle' => ['sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even odds', 'target' => 3]]],
            'likelihood' => 'even',
            'chaosFactor' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {"answer": "no", "roll": 4, "sides": 6, "effectiveTarget": 3, "likelihood": "even", "likelihoodLabel": "Even odds", "chaosFactor": null}
                JSON,
            $this->content(),
        );
    }

    #[Test]
    public function explicitNullOptionalFieldsCountAsAbsent(): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(1);

        $this->ask([
            'oracle' => [
                'sides' => 6,
                'levels' => [['key' => 'even', 'label' => 'Even odds', 'target' => 3]],
                'chaos' => null,
                'exceptionalPercent' => null,
            ],
            'likelihood' => 'even',
            'chaosFactor' => null,
        ]);

        // Without exceptional bands, a roll of 1 is a plain yes.
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
                {"answer": "yes", "roll": 1, "sides": 6, "effectiveTarget": 3, "likelihood": "even", "likelihoodLabel": "Even odds", "chaosFactor": null}
                JSON,
            $this->content(),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'too few sides' => [
            ['oracle' => ['sides' => 1, 'levels' => [['key' => 'a', 'label' => 'A', 'target' => 1]]], 'likelihood' => 'a'],
            'A likelihood oracle rolls a die of 2 to 1000 sides, 1 given.',
        ];
        yield 'target above the sides' => [
            ['oracle' => ['sides' => 6, 'levels' => [['key' => 'a', 'label' => 'A', 'target' => 7]]], 'likelihood' => 'a'],
            'Likelihood level "a" has a target of 0 to 6, 7 given.',
        ];
        yield 'empty definition' => [['oracle' => [], 'likelihood' => 'a'], 'A likelihood oracle has a "sides" integer.'];
        yield 'unknown likelihood' => [
            ['oracle' => self::oracle(), 'likelihood' => 'certain'],
            'There is no likelihood level "certain"; the levels are "unlikely", "likely", "nearly-certain".',
        ];
        yield 'chaos factor out of range' => [
            ['oracle' => self::oracle(), 'likelihood' => 'likely', 'chaosFactor' => 10],
            'The chaos factor is between 1 and 9, 10 given.',
        ];
        yield 'chaos factor without chaos' => [
            ['oracle' => ['sides' => 6, 'levels' => [['key' => 'a', 'label' => 'A', 'target' => 3]]], 'likelihood' => 'a', 'chaosFactor' => 5],
            'This likelihood oracle has no chaos factor, 5 given.',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidRequests')]
    public function anInvalidOracleOrQuestionIsUnprocessable(array $body, string $error): void
    {
        $this->signIn(['SOLO_PLAYER']);
        $this->script(1);

        $this->ask($body);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['error' => $error], $this->json());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'invalid JSON' => ['{"oracle": '];
        yield 'not an object' => ['"likely"'];
        yield 'missing oracle' => ['{"likelihood": "likely"}'];
        yield 'non-object oracle' => ['{"oracle": "fate", "likelihood": "likely"}'];
        yield 'list oracle' => ['{"oracle": [{"sides": 100}], "likelihood": "likely"}'];
        yield 'missing likelihood' => ['{"oracle": {}}'];
        yield 'non-string likelihood' => ['{"oracle": {}, "likelihood": 65}'];
        yield 'non-integer chaos factor' => ['{"oracle": {}, "likelihood": "likely", "chaosFactor": "5"}'];
        yield 'fractional chaos factor' => ['{"oracle": {}, "likelihood": "likely", "chaosFactor": 5.5}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequest(string $body): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/likelihood-answers', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => self::MALFORMED_BODY], $this->json());
    }

    #[Test]
    public function aFormEncodedBodyIsRejected(): void
    {
        $this->signIn(['SOLO_PLAYER']);

        $this->client->request('POST', '/api/likelihood-answers', ['likelihood' => 'likely']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the question as JSON.'], $this->json());
    }

    #[Test]
    public function askingNeedsASession(): void
    {
        $this->ask(['oracle' => self::oracle(), 'likelihood' => 'likely']);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonStringEqualsJsonString('{"error":"Authentication required."}', $this->content());
    }

    /**
     * @return array<string, mixed>
     */
    private static function oracle(): array
    {
        return [
            'sides' => 100,
            'levels' => [
                ['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35],
                ['key' => 'likely', 'label' => 'Likely', 'target' => 65],
                ['key' => 'nearly-certain', 'label' => 'Nearly certain', 'target' => 95],
            ],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
            'exceptionalPercent' => 20,
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
    private function ask(array $body): void
    {
        $this->client->jsonRequest('POST', '/api/likelihood-answers', $body);
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
