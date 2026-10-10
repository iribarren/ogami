<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignIdGenerator;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CreateCampaign;
use App\Play\Application\EndSession;
use App\Play\Application\GetCampaign;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\SetTrackerValue;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Application\SwitchSceneType;
use App\Play\Application\TrackerView;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\HookSceneHasNoSceneType;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnknownSceneType;
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
 * A solo player's campaigns, with their Flow, sessions, scenes (with their Scene Types) and
 * Trackers (security.yaml restricts these routes to ROLE_SOLO_PLAYER). A campaign of another
 * player is not found, exactly like an unknown one. A key of the release that does not exist (a
 * Flow, a Tracker, a Scene Type) is not found either, whether it comes in the path or in the body.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignController
{
    use ReadsJsonBodies;

    private const string MALFORMED_CAMPAIGN = 'Send a JSON object with a string "name", a string "gameSystemKey" and, to play a Flow, a string "flowKey", such as {"name": "The lost mine", "gameSystemKey": "ironsworn"}.';
    private const string MALFORMED_SCENE = 'Send a JSON object with a string "title", a string "sceneType" or both, such as {"title": "At the gate"} or {"sceneType": "legwork"}.';
    private const string MALFORMED_SCENE_TYPE = 'Send a JSON object with a string "sceneType", such as {"sceneType": "firefight"}.';
    private const string MALFORMED_TRACKER = 'Send a JSON object with an integer "value", such as {"value": 3}.';

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
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name" or "gameSystemKey", or a "flowKey" that is not a string.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The GameSystem has no published release, or its latest release has no Flow with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
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
        $flowKey = $body['flowKey'] ?? null;
        if (!\is_string($name) || !\is_string($gameSystemKey) || (null !== $flowKey && !\is_string($flowKey))) {
            return $this->error(self::MALFORMED_CAMPAIGN, Response::HTTP_BAD_REQUEST);
        }

        $campaignId = $this->campaignIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new CreateCampaign($campaignId, $user->id(), $name, $gameSystemKey, $flowKey));
        } catch (InvalidCampaignName $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnknownFlow $exception) {
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

    #[Route('/api/campaigns/{campaignId}/sessions/current/end', name: 'api_campaigns_sessions_current_end', methods: ['POST'])]
    #[OA\Post(operationId: 'endSession', summary: 'End the current session of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The ended session. No scene starts until the next session.', content: new OA\JsonContent(ref: new Model(type: SessionResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'No session is under way (none has started, or it has ended), or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function endSession(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new EndSession($campaignId, $user->id()));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NoCurrentSession|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $session = array_last($this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->sessions) ?? throw new \LogicException('The session was just ended.');

        return new JsonResponse(SessionResponse::fromView($session));
    }

    #[Route('/api/campaigns/{campaignId}/scenes', name: 'api_campaigns_scenes_start', methods: ['POST'])]
    #[OA\Post(operationId: 'startScene', summary: 'Start the next scene in the current session of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: StartSceneRequest::class)))]
    #[OA\Response(response: 201, description: 'The campaign, the new scene current.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed: neither a string "title" nor a string "sceneType", or one of them is not a string.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no Scene Type with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'No session is under way (none has started, or it has ended), the current session holds the most scenes it can, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The title is blank or too long.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function startScene(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the scene as JSON.', self::MALFORMED_SCENE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $title = $body['title'] ?? null;
        $sceneType = $body['sceneType'] ?? null;
        if ((null !== $title && !\is_string($title)) || (null !== $sceneType && !\is_string($sceneType)) || (null === $title && null === $sceneType)) {
            return $this->error(self::MALFORMED_SCENE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new StartScene($campaignId, $user->id(), $title, $sceneType));
        } catch (CampaignNotFound|UnknownSceneType $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NoCurrentSession|CampaignLimitReached|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidSceneTitle $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->campaign($campaignId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/scenes/current/scene-type', name: 'api_campaigns_scenes_current_scene_type', methods: ['POST'])]
    #[OA\Post(operationId: 'switchSceneType', summary: 'Switch the Scene Type of the current scene of one of my campaigns by hand')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SwitchSceneTypeRequest::class)))]
    #[OA\Response(response: 200, description: 'The current scene with its new Scene Type; its number, title and journal entries stay.', content: new OA\JsonContent(ref: new Model(type: SceneResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "sceneType".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no Scene Type with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no current scene, the current scene is a hook Scene, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function switchSceneType(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the Scene Type as JSON.', self::MALFORMED_SCENE_TYPE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $sceneType = $body['sceneType'] ?? null;
        if (!\is_string($sceneType)) {
            return $this->error(self::MALFORMED_SCENE_TYPE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new SwitchSceneType($campaignId, $user->id(), $sceneType));
        } catch (CampaignNotFound|UnknownSceneType $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NoCurrentScene|HookSceneHasNoSceneType|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $sessions = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->sessions;
        $scene = array_last(array_last($sessions)->scenes ?? []) ?? throw new \LogicException('The current scene was just switched.');

        return new JsonResponse(SceneResponse::fromView($scene));
    }

    #[Route('/api/campaigns/{campaignId}/trackers/{trackerKey}', name: 'api_campaigns_trackers_set', methods: ['PUT'])]
    #[OA\Put(operationId: 'setTrackerValue', summary: 'Set the value of a Tracker of one of my campaigns by hand')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetTrackerValueRequest::class)))]
    #[OA\Response(response: 200, description: 'The Tracker with the value kept, clamped to its range.', content: new OA\JsonContent(ref: new Model(type: TrackerResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "value".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no Tracker with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function setTrackerValue(string $campaignId, string $trackerKey, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the value as JSON.', self::MALFORMED_TRACKER);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $value = $body['value'] ?? null;
        if (!\is_int($value)) {
            return $this->error(self::MALFORMED_TRACKER, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new SetTrackerValue($campaignId, $user->id(), $trackerKey, $value));
        } catch (CampaignNotFound|UnknownCampaignTracker $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $trackers = array_filter(
            $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->trackers,
            static fn (TrackerView $tracker): bool => $tracker->key === $trackerKey,
        );

        return new JsonResponse(TrackerResponse::fromView(array_first($trackers) ?? throw new \LogicException('The Tracker was just set.')));
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
}
