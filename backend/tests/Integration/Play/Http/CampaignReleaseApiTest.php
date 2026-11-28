<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCardImageResponse;
use App\Play\Infrastructure\Http\CampaignReleaseController;
use App\Play\Infrastructure\Http\CampaignReleaseResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Studio\TestImages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The two read paths of the tabletop (ADR 0054): the content of the release a campaign is pinned to
 * and the card images that release pinned. Only the campaign's owner reads them, and only what the
 * pinned release holds, not the latest release of its GameSystem nor the draft.
 */
#[CoversClass(CampaignReleaseController::class)]
#[CoversClass(CampaignReleaseResponse::class)]
#[CoversClass(CampaignCardImageResponse::class)]
final class CampaignReleaseApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function theOwnerReadsThePinnedReleaseWithItsImagesPointingAtTheCampaign(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $back = $this->upload('cardBack', TestImages::png());
        $front = $this->upload('cardFront', TestImages::jpeg());
        $this->publish();
        $id = $this->createCampaign();

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $id));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['gameSystemKey', 'version', 'publishedAt', 'content', 'cardImages'], array_keys($body));
        self::assertSame(['mine', 1], [$body['gameSystemKey'], $body['version']]);
        self::assertIsString($body['publishedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $body['publishedAt']);
        self::assertIsArray($body['content']);
        self::assertSame(2, $body['content']['schemaVersion']);
        $expected = array_map(static fn (array $pin): array => [...$pin, 'url' => \sprintf('/api/campaigns/%s/card-images/%s', $id, $pin['sha256'])], [$back, $front]);
        self::assertSame($expected, $body['cardImages']);
    }

    #[Test]
    public function theOwnerReadsTheBytesOfAPinnedImageWithTheStudioHeaders(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $back = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $id = $this->createCampaign();

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $id));
        $images = $this->json()['cardImages'] ?? null;
        self::assertIsArray($images);
        self::assertIsArray($images[0]);
        $url = $images[0]['url'] ?? null;
        self::assertIsString($url);
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame($back['sha256'], hash('sha256', (string) $response->getContent()));
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('inline', $response->headers->get('Content-Disposition'));
        foreach (['private', 'max-age=31536000', 'immutable'] as $directive) {
            self::assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
        }
    }

    #[Test]
    public function aReleasePublishedWithoutImagesHasNoCardImages(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $this->publish();
        $id = $this->createCampaign();

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $id));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['cardImages']);
    }

    #[Test]
    public function aCampaignKeepsReadingItsPinnedReleaseWhenALaterReleaseHasImages(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $this->publish();
        $id = $this->createCampaign();
        $this->login('manager@example.com');
        $later = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $this->login('player@example.com');

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $id));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([1, []], [$this->json()['version'], $this->json()['cardImages']]);
        $this->client->request('GET', \sprintf('/api/campaigns/%s/card-images/%s', $id, $later['sha256']));
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anImageOfAnotherReleaseOrOnlyOfTheDraftOrOfNoOneIsNotFound(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $this->upload('cardBack', TestImages::png());
        $this->publish();
        $id = $this->createCampaign();
        $this->login('manager@example.com');
        $newer = $this->upload('cardBack', TestImages::jpeg());
        $this->publish();
        $draftOnly = $this->upload('cardBack', TestImages::png(900, 1226));
        $this->login('player@example.com');

        foreach ([$newer['sha256'], $draftOnly['sha256'], str_repeat('0', 64)] as $sha256) {
            $this->client->request('GET', \sprintf('/api/campaigns/%s/card-images/%s', $id, $sha256));
            self::assertResponseStatusCodeSame(404, $sha256);
            self::assertArrayHasKey('error', $this->json());
        }
    }

    #[Test]
    #[DataProvider('malformedHashes')]
    public function aMalformedHashIsNotARoute(string $sha256): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/campaigns/'.self::UNKNOWN_ID.'/card-images/'.$sha256);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHashes(): iterable
    {
        yield 'too short' => ['abc123'];
        yield 'upper case' => [str_repeat('A', 64)];
        yield 'not hexadecimal' => [str_repeat('g', 64)];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $back = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);

        foreach ([$id, self::UNKNOWN_ID, 'not-an-id'] as $campaignId) {
            $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $campaignId));
            self::assertResponseStatusCodeSame(404, $campaignId);
            $this->client->request('GET', \sprintf('/api/campaigns/%s/card-images/%s', $campaignId, $back['sha256']));
            self::assertResponseStatusCodeSame(404, $campaignId);
        }
    }

    #[Test]
    public function aPinnedReleaseThatCannotBeFoundIsAServerFault(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $back = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $id = $this->createCampaign();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE play_campaign SET release_version = 9 WHERE id = :id', ['id' => $id]);

        foreach (['release', 'card-images/'.$back['sha256']] as $path) {
            $this->client->request('GET', \sprintf('/api/campaigns/%s/%s', $id, $path));
            self::assertResponseStatusCodeSame(500, $path);
            self::assertSame('The GameSystem release this campaign plays can no longer be read.', $this->json()['error'] ?? null);
        }
    }

    #[Test]
    public function aPinnedReleaseWhoseContentNoLongerParsesIsAServerFaultNotAnEmptyAnswer(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $this->publish();
        $id = $this->createCampaign();
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE studio_gamesystem_release SET content = '{\"schemaVersion\": 99}'::jsonb");

        $this->client->request('GET', \sprintf('/api/campaigns/%s/release', $id));

        self::assertResponseStatusCodeSame(500);
        self::assertSame('The GameSystem release this campaign plays can no longer be read.', $this->json()['error'] ?? null);
    }

    #[Test]
    public function aPinnedImageWhoseBytesAreMissingIsAServerFaultNotANotFound(): void
    {
        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        $this->createDraft();
        $back = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $id = $this->createCampaign();
        $connection = self::getContainer()->get(Connection::class);
        // Only the bytes go: the release still pins the hash, as after a lost row.
        $connection->executeStatement('SET session_replication_role = replica');
        $connection->executeStatement('DELETE FROM studio_image WHERE sha256 = :sha256', ['sha256' => $back['sha256']]);
        $connection->executeStatement('SET session_replication_role = DEFAULT');

        $this->client->request('GET', \sprintf('/api/campaigns/%s/card-images/%s', $id, $back['sha256']));

        self::assertResponseStatusCodeSame(500);
        self::assertIsString($this->json()['error'] ?? null);
    }

    #[Test]
    public function bothPathsNeedASoloPlayer(): void
    {
        $path = '/api/campaigns/'.self::UNKNOWN_ID;
        $this->client->request('GET', $path.'/release');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', $path.'/card-images/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(401);

        $this->signIn('manager@example.com', ['GAME_MANAGER']);
        foreach (['/release', '/card-images/'.str_repeat('a', 64)] as $suffix) {
            $this->client->request('GET', $path.$suffix);
            self::assertResponseStatusCodeSame(403, $suffix);
        }
    }

    private function createCampaign(): string
    {
        $this->signIn('player@example.com', ['SOLO_PLAYER']);
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'mine']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createDraft(): void
    {
        $this->client->jsonRequest('POST', '/api/studio/game-systems', ['key' => 'mine', 'name' => 'Mine']);
        self::assertResponseStatusCodeSame(201);
    }

    private function publish(): void
    {
        $this->client->jsonRequest('POST', '/api/studio/game-systems/mine/releases');
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string}
     */
    private function upload(string $slot, string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        \assert(\is_string($path));
        file_put_contents($path, $bytes);
        $this->client->request('PUT', '/api/studio/game-systems/mine/card-images/'.$slot, [], ['image' => new UploadedFile($path, 'card.png', null, \UPLOAD_ERR_OK, true)]);
        self::assertResponseStatusCodeSame(200);

        /** @var array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string} $pin */
        $pin = $this->json();

        return $pin;
    }

    private function login(string $email): void
    {
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->login($email);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
