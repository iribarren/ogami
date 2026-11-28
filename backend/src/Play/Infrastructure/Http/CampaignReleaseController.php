<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CardImageNotFound;
use App\Play\Application\CardImageUnavailable;
use App\Play\Application\GetCampaignCardImage;
use App\Play\Application\GetCampaignRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The two read paths of the tabletop (ADR 0054; security.yaml restricts these routes to
 * ROLE_SOLO_PLAYER): the content of the release a campaign is pinned to and the card images that
 * release pinned. A campaign of another player is not found, exactly like an unknown one.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignReleaseController
{
    use ReadsJsonBodies;

    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/release', name: 'api_campaigns_release_read', methods: ['GET'])]
    #[OA\Get(operationId: 'readCampaignRelease', summary: 'Read the release one of my campaigns is pinned to, with its card images')]
    #[OA\Response(response: 200, description: 'The pinned release as published: its whole content document and the card images it pinned, never the latest release of its GameSystem.', content: new OA\JsonContent(ref: new Model(type: CampaignReleaseResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 500, description: 'The pinned release cannot be read.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function release(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $release = $this->queryBus->ask(new GetCampaignRelease($user->id(), $campaignId));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return new JsonResponse(CampaignReleaseResponse::fromView($release, $campaignId));
    }

    #[Route('/api/campaigns/{campaignId}/card-images/{sha256}', name: 'api_campaigns_card_images_read', requirements: ['sha256' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[OA\Get(operationId: 'readCampaignCardImage', summary: 'Read the bytes of an image the release of one of my campaigns pinned')]
    #[OA\Response(response: 200, description: 'The image, with its stored content type. The URL holds the hash, so it is cached for good.', content: [new OA\MediaType(mediaType: 'image/png', schema: new OA\Schema(type: 'string', format: 'binary')), new OA\MediaType(mediaType: 'image/jpeg', schema: new OA\Schema(type: 'string', format: 'binary'))])]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or the release it is pinned to did not pin an image with this hash.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 500, description: 'The pinned release cannot be found.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function cardImage(string $campaignId, string $sha256, #[CurrentUser] AuthenticatedUser $user): Response
    {
        try {
            $image = $this->queryBus->ask(new GetCampaignCardImage($user->id(), $campaignId, $sha256));
        } catch (CampaignNotFound|CardImageNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound) {
            return $this->unreadableRelease();
        } catch (CardImageUnavailable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response($image->bytes, Response::HTTP_OK, [
            'Content-Type' => $image->mediaType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
