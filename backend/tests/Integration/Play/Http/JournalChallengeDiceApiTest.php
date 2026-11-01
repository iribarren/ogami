<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Infrastructure\Http\CheckChallengeContentResponse;
use App\Play\Infrastructure\Http\CheckChallengeResponse;
use App\Play\Infrastructure\Http\CheckContentResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Challenge dice over the Play API (ADR 0049), on valid/v2-challenge-dice without its burn: the action
 * check "edge" (1d6 + {edge} against 2d10, capped at 10, momentum from "momentum") and the progress check
 * "fulfill" ({vow} / 4 against 2d10). A Character has edge 2, momentum 2 and the vow ticks it was given.
 *
 * @phpstan-type Entry array{id: string, content: array{bandKey: string, total: int, expression: string, groups: list<mixed>, diceTotal: int, modifier: int, special: string|null, pool: null, challenge: array{sides: int, scoreMax: int|null, score: int, momentum: int|null, cancelled: bool, dice: list<array{face: int, beaten: bool}>}}}
 */
#[CoversClass(JournalController::class)]
#[CoversClass(CheckContentResponse::class)]
#[CoversClass(CheckChallengeContentResponse::class)]
#[CoversClass(CheckChallengeResponse::class)]
final class JournalChallengeDiceApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

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

        $content = ReleaseArrays::without(ReleaseViews::fixtureContent('valid/v2-challenge-dice'), 'checks.0.push');
        $bus = $container->get(CommandBus::class);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9303', $content, false));
        $bus->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignTellsTheChallengeOfEachCheck(): void
    {
        [$id] = $this->campaignInAScene();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertIsArray($campaign['checks'] ?? null);
        /** @var array<string, array{challenge: array<string, mixed>|null, dice: string|null, pool: array<string, mixed>|null}> $checks */
        $checks = array_column($campaign['checks'], null, 'key');
        self::assertSame(['count' => 2, 'sides' => 10, 'scoreMax' => 10, 'momentumLabel' => 'Momentum'], $checks['edge']['challenge']);
        self::assertSame('1d6', $checks['edge']['dice']);
        self::assertSame(['count' => 2, 'sides' => 10, 'scoreMax' => null, 'momentumLabel' => null], $checks['fulfill']['challenge']);
        self::assertNull($checks['fulfill']['dice']);
        self::assertNull($checks['fulfill']['pool']);
    }

    #[Test]
    public function anActionRollJournalsTheScoreAndEachChallengeDie(): void
    {
        [$id, $ada] = $this->campaignInAScene();

        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);

        $content = $entry['content'];
        self::assertSame(['weak', 1, '1d6', 4, 2, null], [$content['bandKey'], $content['total'], $content['expression'], $content['diceTotal'], $content['modifier'], $content['special']]);
        self::assertSame(['sides' => 10, 'scoreMax' => 10, 'score' => 6, 'momentum' => 2, 'cancelled' => false, 'dice' => [['face' => 3, 'beaten' => true], ['face' => 9, 'beaten' => false]]], $content['challenge']);
        self::assertSame($entry, $this->journal($id)[0], 'What was stored reads back as the same entry.');
    }

    #[Test]
    public function aProgressRollHasNoActionDie(): void
    {
        [$id, $ada] = $this->campaignInAScene();

        $entry = $this->roll($id, $ada, 'fulfill', [3, 8]);

        $content = $entry['content'];
        self::assertSame(['2d10', [], 0, 7, 1, 'weak'], [$content['expression'], $content['groups'], $content['diceTotal'], $content['modifier'], $content['total'], $content['bandKey']]);
        self::assertSame([7, null, false], [$content['challenge']['score'], $content['challenge']['momentum'], $content['challenge']['cancelled']]);
        self::assertSame($entry, $this->journal($id)[0]);
    }

    #[Test]
    public function aMatchIsASpecialResultAndNegativeMomentumCancelsTheActionDie(): void
    {
        [$id, $ada] = $this->campaignInAScene(['momentum' => -4]);

        $entry = $this->roll($id, $ada, 'edge', [4, 5, 5]);

        self::assertSame(['match', 4, true, 2], [$entry['content']['special'], $entry['content']['diceTotal'], $entry['content']['challenge']['cancelled'], $entry['content']['challenge']['score']]);
        self::assertSame(0, $entry['content']['total']);
        self::assertSame('miss', $entry['content']['bandKey']);
    }

    #[Test]
    public function aChallengeRollCannotBePushed(): void
    {
        [$id, $ada] = $this->campaignInAScene();
        $entry = $this->roll($id, $ada, 'edge', [4, 3, 9]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $id, $entry['id']), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($entry, $this->journal($id)[0], 'The entry stays as it was rolled.');
    }

    /**
     * @param array<string, int> $values
     *
     * @return array{string, string} the campaign and the Character
     */
    private function campaignInAScene(array $values = ['vow' => 28]): array
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

        /** @var Entry $entry */
        $entry = $this->json();

        return $entry;
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
