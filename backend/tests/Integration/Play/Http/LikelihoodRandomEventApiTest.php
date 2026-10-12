<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Infrastructure\Http\JournalController;
use App\Play\Infrastructure\Http\LikelihoodContentResponse;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A fate question of a release whose likelihood oracle declares a random event rule: a double
 * within the chaos factor flags the journal entry with the table to roll.
 */
#[CoversClass(JournalController::class)]
#[CoversClass(LikelihoodContentResponse::class)]
final class LikelihoodRandomEventApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-06T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80'));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);
    }

    #[Test]
    public function aDoubleWithinTheChaosFactorFlagsTheEntryWithTheTableToRoll(): void
    {
        $id = $this->campaignInAScene();
        $this->random->script(33);

        $this->ask($id, 3);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(33, $content['roll']);
        self::assertSame('focus', $content['randomEvent']);

        // The table to roll is one of the campaign's oracle tables, so the client can name it.
        $this->client->request('GET', \sprintf('/api/campaigns/%s', $id));
        $tables = $this->json()['oracleTables'] ?? null;
        self::assertIsArray($tables);
        self::assertContains('focus', array_column($tables, 'key'));

        // The flag is stored with the entry, so the journal lists it again.
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        $entry = $this->json()[0] ?? null;
        self::assertIsArray($entry);
        self::assertIsArray($entry['content']);
        self::assertSame('focus', $entry['content']['randomEvent']);
    }

    #[Test]
    public function aDoubleAboveTheChaosFactorIsNotFlagged(): void
    {
        $id = $this->campaignInAScene();
        $this->random->script(44);

        $this->ask($id, 3);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertNull($content['randomEvent']);
    }

    private function ask(string $campaignId, int $chaosFactor): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/likelihood-oracles/fate', $campaignId), ['likelihood' => 'even', 'chaosFactor' => $chaosFactor]);
    }

    private function campaignInAScene(): string
    {
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001', ReleaseViews::fixtureContent('valid/v2-random-event'), false));
        $userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The lost mine', 'gameSystemKey' => 'minimal']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);
        self::assertResponseStatusCodeSame(201);

        return $id;
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
