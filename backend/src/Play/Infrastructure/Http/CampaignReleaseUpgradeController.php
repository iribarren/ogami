<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\GetCampaign;
use App\Play\Application\PreviewReleaseUpgrade;
use App\Play\Application\UpgradeCampaignRelease;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradeChanged;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The release upgrade of a solo player's campaign (ADR 0035; security.yaml restricts these routes to
 * ROLE_SOLO_PLAYER): preview what moving to the latest release of the GameSystem changes, then
 * confirm it. A campaign of another player is not found, exactly like an unknown one.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignReleaseUpgradeController
{
    use ReadsJsonBodies;

    private const string MALFORMED_UPGRADE = 'Send a JSON object with an integer "toVersion" and a string "fingerprint", such as {"toVersion": 3, "fingerprint": "9f2c4e0a..."}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/release-upgrade', name: 'api_campaigns_release_upgrade_preview', methods: ['GET'])]
    #[OA\Get(operationId: 'previewReleaseUpgrade', summary: 'Preview upgrading one of my campaigns to the latest release of its GameSystem')]
    #[OA\Response(response: 200, description: 'Whether the campaign is up to date and, when it is not, the plan: what the upgrade changes and the fingerprint to confirm it with. Nothing is changed.', content: new OA\JsonContent(ref: new Model(type: ReleaseUpgradeResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 500, description: 'The pinned or the latest release cannot be read.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function preview(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $preview = $this->queryBus->ask(new PreviewReleaseUpgrade($campaignId, $user->id()));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return new JsonResponse(ReleaseUpgradeResponse::fromPreview($preview));
    }

    #[Route('/api/campaigns/{campaignId}/release-upgrade', name: 'api_campaigns_release_upgrade_confirm', methods: ['POST'])]
    #[OA\Post(operationId: 'upgradeCampaignRelease', summary: 'Upgrade one of my campaigns to the latest release of its GameSystem, as previewed')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReleaseUpgradeRequest::class)))]
    #[OA\Response(response: 200, description: 'The upgraded campaign, pinned to the new release.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "toVersion" or no string "fingerprint".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already plays this release or a newer one; the upgrade is not the previewed one any more (the campaign was edited, the version is not the latest release, or no such release exists), nothing changed; or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: '"toVersion" is not positive or "fingerprint" is blank.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 500, description: 'The pinned or the target release cannot be read.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function upgrade(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the upgrade as JSON.', self::MALFORMED_UPGRADE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $toVersion = $body['toVersion'] ?? null;
        $fingerprint = $body['fingerprint'] ?? null;
        if (!\is_int($toVersion) || !\is_string($fingerprint)) {
            return $this->error(self::MALFORMED_UPGRADE, Response::HTTP_BAD_REQUEST);
        }

        if ($toVersion < 1 || '' === trim($fingerprint)) {
            return $this->error('The "toVersion" must be positive and the "fingerprint" not blank.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new UpgradeCampaignRelease($campaignId, $user->id(), $toVersion, $fingerprint));
            $campaign = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignUpToDate|ReleaseUpgradeChanged|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return new JsonResponse(CampaignResponse::fromView($campaign));
    }
}
