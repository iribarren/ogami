<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Infrastructure\Http\GameSystemReleaseContentResponse;
use App\Studio\Infrastructure\Http\GameSystemReleaseController;
use App\Tests\Support\Studio\TestImages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Releases pin the card images of their draft when they are published, and Studio reads a release's
 * whole content back, for printing (ADR 0053).
 */
#[CoversClass(GameSystemReleaseController::class)]
#[CoversClass(GameSystemReleaseContentResponse::class)]
final class GameSystemReleaseCardImagesApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems/mine';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function publishingPinsTheDraftsImagesAndTheContentEndpointListsThem(): void
    {
        $this->signInWithDraft('ann');
        $back = $this->upload('cardBack', TestImages::png());
        $front = $this->upload('cardFront', TestImages::jpeg());

        $this->publish();
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', self::BASE.'/releases/1/content');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['gameSystemKey', 'version', 'publishedAt', 'content', 'cardImages'], array_keys($body));
        self::assertSame(['mine', 1], [$body['gameSystemKey'], $body['version']]);
        self::assertSame([$back, $front], $body['cardImages']);
        $content = $body['content'];
        self::assertIsArray($content);
        self::assertSame('Mine', $this->arrayAt($content, 'gameSystem')['name']);
        self::assertArrayHasKey('oracles', $content);
        self::assertSame(2, $content['schemaVersion']);
        self::assertSame([], $content['flows']);
    }

    #[Test]
    public function aReleasePublishedWithoutImagesHasNoCardImages(): void
    {
        $this->signInWithDraft('ann');
        $this->publish();

        $this->client->request('GET', self::BASE.'/releases/1/content');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['cardImages']);
        self::assertSame(0, $this->rows('studio_release_card_image_pin'));
    }

    #[Test]
    public function aLaterUploadNeverChangesWhatAPublishedReleaseHolds(): void
    {
        $this->signInWithDraft('ann');
        $old = $this->upload('cardBack', TestImages::png());
        $this->publish();

        $new = $this->upload('cardBack', TestImages::jpeg());
        $this->client->request('GET', self::BASE.'/releases/1/content');

        self::assertSame([$old], $this->json()['cardImages']);
        self::assertNotSame($old['sha256'], $new['sha256']);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$new], $this->json());
    }

    #[Test]
    public function aPinnedImageStaysReadableAfterTheDraftReplacesOrRemovesItAndOnlyForItsOwner(): void
    {
        $this->signInWithDraft('ann');
        $old = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $this->upload('cardBack', TestImages::jpeg());
        $this->client->request('DELETE', self::BASE.'/card-images/cardBack');

        $this->client->request('GET', $old['url']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($old['sha256'], hash('sha256', (string) $this->client->getResponse()->getContent()));
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->request('GET', $old['url']);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/studio/game-systems', ['key' => 'mine', 'name' => 'Bob\'s']);
        $this->client->request('GET', $old['url']);
        self::assertResponseStatusCodeSame(404, 'Another manager\'s GameSystem with the same key does not use the image.');
    }

    #[Test]
    public function anImageThatNoDraftAndNoReleasePinsAnyMoreIsDeleted(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $this->upload('cardBack', TestImages::jpeg());

        self::assertSame(1, $this->rows('studio_image'));
        $this->publish();
        $this->client->request('DELETE', self::BASE.'/card-images/cardBack');
        self::assertSame(1, $this->rows('studio_image'), 'The release keeps it.');
    }

    #[Test]
    public function publishingOnlyANewImageIsAChangeButTheSameImagesAreNot(): void
    {
        $this->signInWithDraft('ann');
        $this->publish();

        $this->publish();
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $this->json()['code'] ?? null);

        $this->upload('cardBack', TestImages::png());
        $this->publish();
        self::assertResponseStatusCodeSame(201);
        $this->publish();
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $this->json()['code'] ?? null);
        self::assertSame(1, $this->rows('studio_release_card_image_pin'), 'The refused publish pinned nothing.');
    }

    #[Test]
    public function aReplacedOrRemovedImageAloneIsAlsoAChange(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $this->publish();

        $this->upload('cardBack', TestImages::jpeg());
        $this->publish();
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', self::BASE.'/card-images/cardBack');
        $this->publish();
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', self::BASE.'/releases/3/content');
        self::assertSame([], $this->json()['cardImages']);
    }

    #[Test]
    public function restoringPutsTheReleasesImagesInTheDraftAndALaterPublishIsUnchanged(): void
    {
        $this->signInWithDraft('ann');
        $kept = $this->upload('cardBack', TestImages::png());
        $this->publish();
        $replaced = $this->upload('cardBack', TestImages::jpeg());
        $extra = $this->upload('cardFront', TestImages::png(900, 1226));

        $this->client->jsonRequest('POST', self::BASE.'/restore', ['version' => 1]);

        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$kept], $this->json(), 'The slot the release left empty is emptied.');
        $this->client->request('GET', $replaced['url']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $extra['url']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->rows('studio_image'), 'Images nobody pins any more are deleted.');
        $this->publish();
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $this->json()['code'] ?? null);
    }

    #[Test]
    public function aRefusedRestoreChangesNoImage(): void
    {
        $this->signInWithDraft('ann');
        $image = $this->upload('cardBack', TestImages::png());

        $this->client->jsonRequest('POST', self::BASE.'/restore', ['version' => 4]);

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$image], $this->json());
    }

    #[Test]
    public function theContentIsOwnerOnlyAndNeedsAKnownKeyAndVersion(): void
    {
        $this->signInWithDraft('ann');
        $this->publish();

        foreach (['/releases/2/content', '/releases/0/content'] as $path) {
            $this->client->request('GET', self::BASE.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('GET', '/api/studio/game-systems/unknown/releases/1/content');
        self::assertResponseStatusCodeSame(404);
        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        $this->client->request('GET', self::BASE.'/releases/1/content');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theContentNeedsAGameManager(): void
    {
        $this->client->request('GET', self::BASE.'/releases/1/content');
        self::assertResponseStatusCodeSame(401);

        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);
            $this->client->request('GET', self::BASE.'/releases/1/content');
            self::assertResponseStatusCodeSame(403, $role);
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function arrayAt(array $node, string $key): array
    {
        self::assertIsArray($node[$key]);

        return $node[$key];
    }

    /**
     * @return array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string}
     */
    private function upload(string $slot, string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        \assert(\is_string($path));
        file_put_contents($path, $bytes);
        $this->client->request('PUT', self::BASE.'/card-images/'.$slot, [], ['image' => new UploadedFile($path, 'card.png', null, \UPLOAD_ERR_OK, true)]);
        self::assertResponseStatusCodeSame(200);

        /** @var array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string} $pin */
        $pin = $this->json();

        return $pin;
    }

    private function publish(): void
    {
        $this->client->jsonRequest('POST', self::BASE.'/releases');
    }

    private function signInWithDraft(string $name): void
    {
        $this->signIn($name.'@example.com', ['GAME_MANAGER']);
        $this->client->jsonRequest('POST', '/api/studio/game-systems', ['key' => 'mine', 'name' => 'Mine']);
        self::assertResponseStatusCodeSame(201);
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

    private function rows(string $table): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
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
