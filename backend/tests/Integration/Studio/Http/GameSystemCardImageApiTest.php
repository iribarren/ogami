<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Infrastructure\Http\CardImageRefusedResponse;
use App\Studio\Infrastructure\Http\CardImageResponse;
use App\Studio\Infrastructure\Http\GameSystemCardImageController;
use App\Tests\Support\Studio\TestImages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The card back and front frame of a GameSystem draft: owner-only, multipart upload, re-encoded
 * images (ADR 0053).
 */
#[CoversClass(GameSystemCardImageController::class)]
#[CoversClass(CardImageResponse::class)]
#[CoversClass(CardImageRefusedResponse::class)]
final class GameSystemCardImageApiTest extends WebTestCase
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
    public function noSessionIsUnauthorizedAndOtherRolesAreForbidden(): void
    {
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertResponseStatusCodeSame(401);

        foreach (['SOLO_PLAYER', 'OWNER'] as $role) {
            $this->signIn($role.'@example.com', [$role]);
            foreach ([['GET', '/card-images'], ['PUT', '/card-images/cardBack'], ['DELETE', '/card-images/cardBack'], ['GET', '/images/'.str_repeat('a', 64)]] as [$method, $path]) {
                $this->client->request($method, self::BASE.$path);
                self::assertResponseStatusCodeSame(403, $role.' '.$method.' '.$path);
            }
        }
    }

    #[Test]
    public function aGameSystemThatNeverHadImagesListsNone(): void
    {
        $this->signInWithDraft('ann');

        $this->client->request('GET', self::BASE.'/card-images');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->jsonList());
    }

    #[Test]
    public function uploadingStoresTheReEncodedImageAndTheListAndReadServeIt(): void
    {
        $this->signInWithDraft('ann');

        $this->upload('cardBack', TestImages::png());

        self::assertResponseStatusCodeSame(200);
        $pin = $this->pin();
        self::assertSame(['slot', 'sha256', 'mediaType', 'width', 'height', 'url'], array_keys($this->json()));
        self::assertSame('cardBack', $pin['slot']);
        self::assertSame('image/png', $pin['mediaType']);
        self::assertSame([815, 1110], [$pin['width'], $pin['height']]);
        self::assertSame(self::BASE.'/images/'.$pin['sha256'], $pin['url']);

        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$pin], $this->jsonList());

        $this->client->request('GET', $pin['url']);
        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('inline', $response->headers->get('Content-Disposition'));
        foreach (['private', 'max-age=31536000', 'immutable'] as $directive) {
            self::assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
        }
        self::assertSame($pin['sha256'], hash('sha256', (string) $response->getContent()));
    }

    #[Test]
    public function aJpegIsServedAsAJpegAndTheListFollowsTheSlotOrder(): void
    {
        $this->signInWithDraft('ann');

        $this->upload('cardFront', TestImages::jpeg());
        $front = $this->pin();
        $this->upload('cardBack', TestImages::png());

        self::assertSame('image/jpeg', $front['mediaType']);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame(['cardBack', 'cardFront'], array_column($this->jsonList(), 'slot'));
        $this->client->request('GET', $front['url']);
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function replacingASlotKeepsTheLastImageAndTheOldOneIsGone(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $old = $this->pin();

        $this->upload('cardBack', TestImages::jpeg());
        $new = $this->pin();

        self::assertNotSame($old['sha256'], $new['sha256']);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$new], $this->jsonList());
        $this->client->request('GET', $old['url']);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anUploadNeverChangesTheDraftOrItsVersion(): void
    {
        $this->signInWithDraft('ann');
        $before = $this->draftRow();

        $this->upload('cardBack', TestImages::png());
        $this->upload('cardFront', TestImages::jpeg());
        $this->client->request('DELETE', self::BASE.'/card-images/cardBack');

        self::assertSame($before, $this->draftRow());
    }

    #[Test]
    public function removingASlotIsNoContentAlsoWhenItWasEmpty(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardFront', TestImages::png());
        $pin = $this->pin();

        $this->client->request('DELETE', self::BASE.'/card-images/cardFront');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', self::BASE.'/card-images/cardFront');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([], $this->jsonList());
        $this->client->request('GET', $pin['url']);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anotherManagersGameSystemIsNotFoundOnEveryEndpoint(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $pin = $this->pin();

        $this->signIn('bob@example.com', ['GAME_MANAGER']);
        foreach ([['GET', '/card-images'], ['DELETE', '/card-images/cardBack'], ['GET', '/images/'.$pin['sha256']]] as [$method, $path]) {
            $this->client->request($method, self::BASE.$path);
            self::assertResponseStatusCodeSame(404, $method.' '.$path);
        }
        $this->upload('cardFront', TestImages::png());
        self::assertResponseStatusCodeSame(404);

        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame(['cardBack'], array_column($this->jsonList(), 'slot'), 'Nothing of Bob\'s attempts changed the images.');
    }

    #[Test]
    public function anImageAnotherGameSystemOwnsIsNotReadableThroughMine(): void
    {
        $this->signInWithDraft('ann');
        $this->client->jsonRequest('POST', '/api/studio/game-systems', ['key' => 'second', 'name' => 'Second']);
        $this->client->request('PUT', '/api/studio/game-systems/second/card-images/cardBack', [], ['image' => $this->file(TestImages::png())]);
        $theirs = $this->pin();

        $this->client->request('GET', self::BASE.'/images/'.$theirs['sha256']);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unknownKeysSlotsAndMalformedHashesAreNotFound(): void
    {
        $this->signInWithDraft('ann');

        foreach ([
            ['GET', '/api/studio/game-systems/unknown/card-images'],
            ['PUT', self::BASE.'/card-images/sideways'],
            ['DELETE', self::BASE.'/card-images/CARDBACK'],
            ['GET', self::BASE.'/images/'.str_repeat('a', 63)],
            ['GET', self::BASE.'/images/'.strtoupper(str_repeat('a', 64))],
            ['GET', self::BASE.'/images/'.str_repeat('a', 64)],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(404, $method.' '.$path);
        }
    }

    #[Test]
    public function aMissingFileOrAWrongFieldNameIsUnprocessable(): void
    {
        $this->signInWithDraft('ann');

        $this->client->request('PUT', self::BASE.'/card-images/cardBack');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"image"', $this->error()['error']);

        $this->client->request('PUT', self::BASE.'/card-images/cardBack', [], ['picture' => $this->file(TestImages::png())]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([], $this->jsonList());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'a GIF' => [TestImages::gif(), 'unsupported_format'];
        yield 'an SVG' => [TestImages::svg(), 'unsupported_format'];
        yield 'garbage bytes' => [TestImages::garbage(), 'unreadable'];
        yield 'an empty file' => ['', 'unreadable'];
        yield 'a PNG header with a corrupt body' => [TestImages::truncatedPng(), 'unreadable'];
        yield 'too few pixels' => [TestImages::png(200, 272), 'too_few_pixels'];
        yield 'too many pixels' => [TestImages::jpeg(1700, 2316), 'too_many_pixels'];
        yield 'a wrong aspect ratio' => [TestImages::png(900, 900), 'wrong_aspect_ratio'];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function aRefusedUploadIsUnprocessableWithItsReasonAndChangesNothing(string $upload, string $reason): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $kept = $this->pin();

        $this->upload('cardBack', $upload);

        self::assertResponseStatusCodeSame(422);
        $body = $this->error();
        self::assertSame($reason, $body['reason'] ?? null);
        self::assertNotSame('', $body['error']);
        $this->client->request('GET', self::BASE.'/card-images');
        self::assertSame([$kept], $this->jsonList(), 'The old pin stays.');
        self::assertSame(1, $this->rows('studio_image'), 'Nothing new is stored.');
    }

    #[Test]
    public function aRequestWithoutTheImageFileIsUnprocessableWithTheMissingFileReason(): void
    {
        $this->signInWithDraft('ann');

        $this->client->request('PUT', self::BASE.'/card-images/cardBack', [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], "--x--\r\n");

        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_file', $this->error()['reason'] ?? null);
        self::assertStringContainsString('"image"', $this->error()['error']);
    }

    #[Test]
    public function theUploadAnswersWithTheStoredImageItself(): void
    {
        $this->signInWithDraft('ann');

        $this->upload('cardFront', TestImages::jpeg());

        self::assertResponseStatusCodeSame(200);
        $pin = $this->pin();
        self::assertSame(hash('sha256', $this->readBytes($pin['url'])), $pin['sha256']);
        self::assertSame(['cardFront', 'image/jpeg', 815, 1110], [$pin['slot'], $pin['mediaType'], $pin['width'], $pin['height']]);
    }

    #[Test]
    public function aFileOverTheUploadLimitIsTooLarge(): void
    {
        $this->signInWithDraft('ann');
        $path = tempnam(sys_get_temp_dir(), 'img');
        \assert(\is_string($path));
        file_put_contents($path, TestImages::png());

        $this->client->request('PUT', self::BASE.'/card-images/cardBack', [], ['image' => new UploadedFile($path, 'big.png', 'image/png', \UPLOAD_ERR_INI_SIZE, true)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('too_large', $this->error()['reason'] ?? null);
    }

    #[Test]
    public function anUploadOverFiveMegabytesIsTooLarge(): void
    {
        $this->signInWithDraft('ann');

        $this->upload('cardBack', TestImages::png().str_repeat("\0", 5 * 1024 * 1024));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('too_large', $this->error()['reason'] ?? null);
    }

    #[Test]
    public function aBodyPhpDroppedForBeingOverPostMaxSizeIsPayloadTooLarge(): void
    {
        $this->signInWithDraft('ann');

        $this->client->request('PUT', self::BASE.'/card-images/cardBack', [], [], ['CONTENT_LENGTH' => 9 * 1024 * 1024, 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']);

        self::assertResponseStatusCodeSame(413);
        self::assertArrayHasKey('error', $this->json());
    }

    #[Test]
    public function discardingTheGameSystemRemovesItsImages(): void
    {
        $this->signInWithDraft('ann');
        $this->upload('cardBack', TestImages::png());
        $this->upload('cardFront', TestImages::png());

        $this->client->request('DELETE', self::BASE);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->rows('studio_card_image_pin'));
        self::assertSame(0, $this->rows('studio_image'));
    }

    private function readBytes(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    private function upload(string $slot, string $bytes): void
    {
        $this->client->request('PUT', self::BASE.'/card-images/'.$slot, [], ['image' => $this->file($bytes)]);
    }

    private function file(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        \assert(\is_string($path));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'card.png', null, \UPLOAD_ERR_OK, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftRow(): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative("SELECT version, updated_at, document FROM studio_gamesystem WHERE game_system_key = 'mine'");
        self::assertIsArray($row);

        return $row;
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
    private function signIn(string $email, array $roles, bool $create = true): void
    {
        $container = self::getContainer();
        if ($create) {
            $id = $container->get(UserIdGenerator::class)->generate()->toString();
            $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));
        }

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

    /**
     * @return array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string}
     */
    private function pin(): array
    {
        /** @var array{slot: string, sha256: string, mediaType: string, width: int, height: int, url: string} $pin */
        $pin = $this->json();

        return $pin;
    }

    /**
     * @return array{error: string, reason?: string}
     */
    private function error(): array
    {
        /** @var array{error: string, reason?: string} $error */
        $error = $this->json();

        return $error;
    }

    /**
     * @return list<array<mixed>>
     */
    private function jsonList(): array
    {
        $decoded = $this->json();
        self::assertTrue(array_is_list($decoded));

        $pins = [];
        foreach ($decoded as $item) {
            self::assertIsArray($item);
            $pins[] = $item;
        }

        return $pins;
    }
}
