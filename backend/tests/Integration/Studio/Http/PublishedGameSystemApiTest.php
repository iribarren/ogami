<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Infrastructure\Http\PublishedGameSystemController;
use App\Studio\Infrastructure\Http\PublishedGameSystemForCopyResponse;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The published GameSystems a game manager can copy a release from. Game managers only; every
 * published GameSystem is listed, whoever owns it.
 */
#[CoversClass(PublishedGameSystemController::class)]
#[CoversClass(PublishedGameSystemForCopyResponse::class)]
final class PublishedGameSystemApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string PATH = '/api/studio/published-game-systems';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function noSessionIsUnauthorizedAndOtherRolesAreForbidden(): void
    {
        $this->client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(401);

        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);
            $this->client->request('GET', self::PATH);
            self::assertResponseStatusCodeSame(403, $role);
        }
    }

    #[Test]
    public function withoutAnyReleaseTheListIsEmpty(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('never-published', 'Never published');

        $this->client->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json());
    }

    #[Test]
    public function itListsTheLatestReleaseOfEveryPublishedGameSystemInOrder(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('zeta', 'zeta');
        $this->publish('zeta');
        $this->createDraft('alpha', 'Alpha');
        $this->publish('alpha');
        $this->client->jsonRequest('PUT', '/api/studio/game-systems/alpha/metadata', ['name' => 'Alpha', 'description' => 'The first one.']);
        $this->publish('alpha');
        $this->createDraft('unpublished', 'Unpublished');

        // Another manager's published GameSystem is listed too; a draft of theirs is not.
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->createDraft('bobs', 'Bob\'s');
        $this->publish('bobs');
        $this->createDraft('bobs-draft', 'Bob\'s draft');

        $this->client->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        $items = $this->json();
        self::assertSame(['alpha', 'bobs', 'zeta'], array_column($items, 'key'));
        self::assertSame(['Alpha', 'Bob\'s', 'zeta'], array_column($items, 'name'));
        self::assertSame([2, 1, 1], array_column($items, 'latestVersion'));
        self::assertSame('The first one.', $items[0]['description']);
        self::assertNull($items[1]['description']);
        foreach ($items as $item) {
            self::assertSame(['key', 'name', 'description', 'latestVersion', 'publishedAt'], array_keys($item));
            self::assertIsString($item['publishedAt']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item['publishedAt']);
        }
    }

    #[Test]
    public function aGameSystemWhoseLatestReleaseNoLongerParsesIsLeftOut(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->createDraft('fine', 'Fine');
        $this->publish('fine');
        $this->createDraft('broken', 'Broken');
        $this->publish('broken');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE studio_gamesystem_release SET content = '{\"broken\": true}'::jsonb WHERE game_system_key = 'broken'");

        $this->client->request('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['fine'], array_column($this->json(), 'key'));
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    private function createDraft(string $key, string $name): void
    {
        $this->client->jsonRequest('POST', '/api/studio/game-systems', ['key' => $key, 'name' => $name]);
        self::assertResponseStatusCodeSame(201);
    }

    private function publish(string $key): void
    {
        $this->client->jsonRequest('POST', '/api/studio/game-systems/'.$key.'/releases', []);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertTrue(array_is_list($decoded));
        /* @var list<array<string, mixed>> $decoded */

        return $decoded;
    }
}
