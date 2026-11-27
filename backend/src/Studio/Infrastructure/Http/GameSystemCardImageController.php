<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\CardImageNotFound;
use App\Studio\Application\GetCardImage;
use App\Studio\Application\ListCardImages;
use App\Studio\Application\RemoveCardImage;
use App\Studio\Application\UploadCardImage;
use App\Studio\Application\UploadedCardImage;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Domain\Image\ImageRefusalReason;
use App\Studio\Domain\Image\ImageRefused;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The card back and the front frame of a GameSystem draft (ADR 0053). Game managers only
 * (security.yaml); another user's GameSystem is not found, exactly like an unknown one. An image
 * is not part of the draft document, so none of this needs or changes the draft's version.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemCardImageController
{
    use ReadsJsonBodies;

    private const string SLOT = 'cardBack|cardFront';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/card-images', name: 'api_studio_card_images_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listCardImages', summary: 'List the card images of one of my GameSystem drafts')]
    #[OA\Response(response: 200, description: 'The images in use, by slot; none when the GameSystem has never had any.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: CardImageResponse::class))))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            /** @var list<CardImagePin> $pins */
            $pins = $this->queryBus->ask(new ListCardImages($user->id(), $key));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(array_map(static fn (CardImagePin $pin): CardImageResponse => CardImageResponse::fromPin($pin, $key), $pins));
    }

    #[Route('/api/studio/game-systems/{key}/card-images/{slot}', name: 'api_studio_card_images_upload', requirements: ['slot' => self::SLOT], methods: ['PUT'])]
    #[OA\Put(operationId: 'uploadCardImage', summary: 'Put an image in a slot, replacing the one there')]
    #[OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['image'], properties: [new OA\Property(property: 'image', description: 'A PNG or JPEG of at most 5 MB, 408 × 555 to 1630 × 2220 px, in the proportions 69:94 (recommended 815 × 1110 px).', type: 'string', format: 'binary')])))]
    #[OA\Parameter(name: 'slot', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['cardBack', 'cardFront']))]
    #[OA\Response(response: 200, description: 'The image now in the slot. The draft\'s version does not change.', content: new OA\JsonContent(ref: new Model(type: CardImageResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the slot is not cardBack or cardFront.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 413, description: 'The request is larger than the server accepts (8 MB).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'There is no "image" file, or the image is refused (the reason says why); nothing was stored.', content: new OA\JsonContent(ref: new Model(type: CardImageRefusedResponse::class)))]
    public function upload(string $key, string $slot, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $slot = CardImageSlot::from($slot);
        if ($this->exceedsPostMaxSize($request)) {
            return $this->error('The request is larger than the server accepts. Images are at most 5 MB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(CardImageRefusedResponse::fromRefusal(ImageRefused::because(ImageRefusalReason::MissingFile)), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $receipt = new UploadedCardImage();
        try {
            $upload = $this->contentsOf($file);
            $this->commandBus->dispatch(new UploadCardImage($user->id(), $key, $slot, $upload, $receipt));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ImageRefused $refusal) {
            return new JsonResponse(CardImageRefusedResponse::fromRefusal($refusal), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The image this request stored, not a re-read of the pins: a concurrent removal may have emptied the slot since.
        return new JsonResponse(CardImageResponse::fromPin(new CardImagePin($slot, $receipt->image()), $key));
    }

    #[Route('/api/studio/game-systems/{key}/card-images/{slot}', name: 'api_studio_card_images_remove', requirements: ['slot' => self::SLOT], methods: ['DELETE'])]
    #[OA\Delete(operationId: 'removeCardImage', summary: 'Empty a card image slot')]
    #[OA\Parameter(name: 'slot', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['cardBack', 'cardFront']))]
    #[OA\Response(response: 204, description: 'The slot is empty, whether or not it held an image.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the slot is not cardBack or cardFront.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function remove(string $key, string $slot, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RemoveCardImage($user->id(), $key, CardImageSlot::from($slot)));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/studio/game-systems/{key}/images/{sha256}', name: 'api_studio_card_images_read', requirements: ['sha256' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[OA\Get(operationId: 'readCardImage', summary: 'Read the bytes of an image one of my GameSystem drafts uses')]
    #[OA\Response(response: 200, description: 'The image, with its stored content type. The URL holds the hash, so it is cached for good.', content: [new OA\MediaType(mediaType: 'image/png', schema: new OA\Schema(type: 'string', format: 'binary')), new OA\MediaType(mediaType: 'image/jpeg', schema: new OA\Schema(type: 'string', format: 'binary'))])]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it does not use an image with this hash.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function read(string $key, string $sha256, #[CurrentUser] AuthenticatedUser $user): Response
    {
        try {
            $image = $this->queryBus->ask(new GetCardImage($user->id(), $key, $sha256));
        } catch (GameSystemNotFound|CardImageNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return new Response($image->bytes, Response::HTTP_OK, [
            'Content-Type' => $image->image->mediaType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * @throws ImageRefused when the file did not arrive whole
     */
    private function contentsOf(UploadedFile $file): string
    {
        if (\UPLOAD_ERR_OK !== $file->getError()) {
            throw ImageRefused::because(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? ImageRefusalReason::TooLarge : ImageRefusalReason::Unreadable);
        }
        $contents = @file_get_contents($file->getPathname());
        if (false === $contents) {
            throw ImageRefused::because(ImageRefusalReason::Unreadable);
        }

        return $contents;
    }

    /**
     * PHP drops the whole body of a request over post_max_size, so the form arrives empty.
     */
    private function exceedsPostMaxSize(Request $request): bool
    {
        $limit = ini_parse_quantity((string) \ini_get('post_max_size'));

        return $limit > 0 && $request->server->getInt('CONTENT_LENGTH') > $limit;
    }
}
