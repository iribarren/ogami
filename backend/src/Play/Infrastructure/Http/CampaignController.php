<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignIdGenerator;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CreateCampaign;
use App\Play\Application\GetCampaign;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A solo player's campaigns, with their sessions and scenes (security.yaml restricts these
 * routes to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly like an
 * unknown one.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignController
{
    private const string MALFORMED_CAMPAIGN = 'Send a JSON object with a string "name" and a string "gameSystemKey", such as {"name": "The lost mine", "gameSystemKey": "ironsworn"}.';
    private const string MALFORMED_SCENE = 'Send a JSON object with a string "title", such as {"title": "At the gate"}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private CampaignIdGenerator $campaignIds,
    ) {
    }

    #[Route('/api/campaigns', name: 'api_campaigns_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listCampaigns', summary: 'List my campaigns')]
    #[OA\Response(
        response: 200,
        description: 'The signed-in player\'s campaigns, newest first.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: CampaignSummaryResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return new JsonResponse(array_map(
            CampaignSummaryResponse::fromView(...),
            $this->queryBus->ask(new ListMyCampaigns($user->id())),
        ));
    }

    #[Route('/api/campaigns', name: 'api_campaigns_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createCampaign', summary: 'Create a campaign pinned to the latest release of a GameSystem')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateCampaignRequest::class)))]
    #[OA\Response(
        response: 201,
        description: 'The new campaign; the Location header is its URL.',
        headers: [new OA\Header(header: 'Location', description: 'The URL of the new campaign.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "name" or "gameSystemKey".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The GameSystem has no published release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The generated campaign id is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The name is blank or too long.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the campaign as JSON.', self::MALFORMED_CAMPAIGN);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $gameSystemKey = $body['gameSystemKey'] ?? null;
        if (!\is_string($name) || !\is_string($gameSystemKey)) {
            return $this->error(self::MALFORMED_CAMPAIGN, Response::HTTP_BAD_REQUEST);
        }

        $campaignId = $this->campaignIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new CreateCampaign($campaignId, $user->id(), $name, $gameSystemKey));
        } catch (InvalidCampaignName $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $response = $this->campaign($campaignId, $user, Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/campaigns/'.$campaignId);

        return $response;
    }

    #[Route('/api/campaigns/{campaignId}', name: 'api_campaigns_get', methods: ['GET'])]
    #[OA\Get(operationId: 'getCampaign', summary: 'Read one of my campaigns')]
    #[OA\Response(response: 200, description: 'The campaign.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function get(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->campaign($campaignId, $user, Response::HTTP_OK);
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/campaigns/{campaignId}/sessions', name: 'api_campaigns_sessions_start', methods: ['POST'])]
    #[OA\Post(operationId: 'startSession', summary: 'Start the next session of one of my campaigns')]
    #[OA\Response(response: 201, description: 'The campaign, the new session current and without a scene.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds the most sessions it can, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function startSession(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new StartSession($campaignId, $user->id()));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->campaign($campaignId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/scenes', name: 'api_campaigns_scenes_start', methods: ['POST'])]
    #[OA\Post(operationId: 'startScene', summary: 'Start the next scene in the current session of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartSceneRequest::class)))]
    #[OA\Response(response: 201, description: 'The campaign, the new scene current.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "title".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no session yet, the current session holds the most scenes it can, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The title is blank or too long.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function startScene(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the scene as JSON.', self::MALFORMED_SCENE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $title = $body['title'] ?? null;
        if (!\is_string($title)) {
            return $this->error(self::MALFORMED_SCENE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new StartScene($campaignId, $user->id(), $title));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NoCurrentSession|CampaignLimitReached|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidSceneTitle $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->campaign($campaignId, $user, Response::HTTP_CREATED);
    }

    /**
     * @throws CampaignNotFound
     */
    private function campaign(string $campaignId, AuthenticatedUser $user, int $status): JsonResponse
    {
        return new JsonResponse(
            CampaignResponse::fromView($this->queryBus->ask(new GetCampaign($campaignId, $user->id()))),
            $status,
        );
    }

    /**
     * @return array<mixed>|JsonResponse the decoded body, or the error response to send
     */
    private function jsonBody(Request $request, string $notJson, string $malformed): array|JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error($notJson, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->error($malformed, Response::HTTP_BAD_REQUEST);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
