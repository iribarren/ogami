<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\EndFlowScene;
use App\Play\Application\GetCampaign;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\MoveOn;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PickSceneType;
use App\Play\Application\PickSceneTypeByOracle;
use App\Play\Application\ResumeGuidance;
use App\Play\Application\SkipFlowStep;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\ChaosFactorBoundToTracker;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Shared\Application\Bus\Command;
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
 * The commands of the FlowRun of a solo player's guided campaign (security.yaml restricts these
 * routes to ROLE_SOLO_PLAYER). Each runs against the campaign's pinned release, keeps nothing
 * when it fails, and answers with the refreshed campaign, FlowRun included. A campaign of another
 * player is not found, exactly like an unknown one. A state the FlowRun is not in (paused, in
 * another step, scene or phase, a campaign played freely) is a conflict; a value the command
 * cannot take is unprocessable.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class FlowRunController
{
    use ReadsJsonBodies;

    private const string MALFORMED_ANSWER = 'Send a JSON object with a string "stepKey", and optionally a string "text", a string "optionKey", a string "likelihood" and an integer "chaosFactor", such as {"stepKey": "intro", "text": "Ada, a fixer"}.';
    private const string MALFORMED_SKIP = 'Send a JSON object with a string "stepKey", such as {"stepKey": "dice"}.';
    private const string MALFORMED_PICK = 'Send a JSON object with a string "sceneType", such as {"sceneType": "tour"}.';
    private const string MALFORMED_END_SCENE = 'Send a JSON object with an integer "sceneNumber", such as {"sceneNumber": 1}.';
    private const string MALFORMED_MOVE_ON = 'Send a JSON object with a string "phase", such as {"phase": "tour"}.';

    private const string NO_SESSION = 'No session.';
    private const string NOT_A_SOLO_PLAYER = 'The user is not a solo player.';
    private const string NOT_JSON = 'The body is not JSON.';
    private const string CAMPAIGN_NOT_FOUND = 'No campaign of the player has this id.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private JournalEntryIdGenerator $entryIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/answer', name: 'api_campaigns_flow_run_answer', methods: ['POST'])]
    #[OA\Post(operationId: 'completeFlowStep', summary: 'Complete the current step of the FlowRun of one of my campaigns and record its result in the journal')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CompleteFlowStepRequest::class)))]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed: no string "stepKey", or a non-string "text", "optionKey" or "likelihood", or a non-integer "chaosFactor".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no oracle (or not the Tracker its chaos is bound to) with the key of the step.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun waits for another step, the next scene does not fit the session (200 scenes), or another request changed the campaign meanwhile (or took the generated entry id).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: self::NOT_JSON, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A field does not belong to the step\'s kind or a required one is missing, the text is blank or too long, the option or likelihood level is unknown, the chaos factor is out of range or not expected (also when the oracle takes it from a campaign Tracker), or the step is a condition step.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function answer(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the answer as JSON.', self::MALFORMED_ANSWER);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $stepKey = $body['stepKey'] ?? null;
        $text = $body['text'] ?? null;
        $optionKey = $body['optionKey'] ?? null;
        $likelihood = $body['likelihood'] ?? null;
        $chaosFactor = $body['chaosFactor'] ?? null;
        if (!\is_string($stepKey) || (null !== $text && !\is_string($text)) || (null !== $optionKey && !\is_string($optionKey)) || (null !== $likelihood && !\is_string($likelihood)) || (null !== $chaosFactor && !\is_int($chaosFactor))) {
            return $this->error(self::MALFORMED_ANSWER, Response::HTTP_BAD_REQUEST);
        }

        return $this->run(new CompleteFlowStep($campaignId, $user->id(), $this->entryIds->generate()->toString(), $stepKey, $text, $optionKey, $likelihood, $chaosFactor), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/skip', name: 'api_campaigns_flow_run_skip', methods: ['POST'])]
    #[OA\Post(operationId: 'skipFlowStep', summary: 'Skip the current step of the FlowRun of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SkipFlowStepRequest::class)))]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "stepKey".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun waits for another step, the step is mandatory, the next scene does not fit the session (200 scenes), or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: self::NOT_JSON, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function skip(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the step as JSON.', self::MALFORMED_SKIP);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $stepKey = $body['stepKey'] ?? null;
        if (!\is_string($stepKey)) {
            return $this->error(self::MALFORMED_SKIP, Response::HTTP_BAD_REQUEST);
        }

        return $this->run(new SkipFlowStep($campaignId, $user->id(), $stepKey), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/pick', name: 'api_campaigns_flow_run_pick', methods: ['POST'])]
    #[OA\Post(operationId: 'pickSceneType', summary: 'Pick the Scene Type of the next scene of the FlowRun of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PickSceneTypeRequest::class)))]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "sceneType".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun is not at the scene pick, the pick does not offer this Scene Type (or rolls on a table), no session is under way, the session holds the most scenes it can, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: self::NOT_JSON, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function pick(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the Scene Type as JSON.', self::MALFORMED_PICK);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $sceneType = $body['sceneType'] ?? null;
        if (!\is_string($sceneType)) {
            return $this->error(self::MALFORMED_PICK, Response::HTTP_BAD_REQUEST);
        }

        return $this->run(new PickSceneType($campaignId, $user->id(), $sceneType), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/pick/roll', name: 'api_campaigns_flow_run_pick_roll', methods: ['POST'])]
    #[OA\Post(operationId: 'pickSceneTypeByOracle', summary: 'Roll the table of the scene pick of the FlowRun of one of my campaigns and start the scene of the entry')]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun is not at the scene pick, the pick does not roll on a table or the entry rolled names no Scene Type, no session is under way, the session holds the most scenes it can, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function pickByOracle(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->run(new PickSceneTypeByOracle($campaignId, $user->id()), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/end-scene', name: 'api_campaigns_flow_run_end_scene', methods: ['POST'])]
    #[OA\Post(operationId: 'endFlowScene', summary: 'End open play in the guided scene of the FlowRun of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: EndFlowSceneRequest::class)))]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "sceneNumber".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun is not in open play in that scene, the next scene does not fit the session (200 scenes), or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: self::NOT_JSON, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function endScene(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the scene number as JSON.', self::MALFORMED_END_SCENE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $sceneNumber = $body['sceneNumber'] ?? null;
        if (!\is_int($sceneNumber)) {
            return $this->error(self::MALFORMED_END_SCENE, Response::HTTP_BAD_REQUEST);
        }

        return $this->run(new EndFlowScene($campaignId, $user->id(), $sceneNumber), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/move-on', name: 'api_campaigns_flow_run_move_on', methods: ['POST'])]
    #[OA\Post(operationId: 'moveOn', summary: 'Move on from the current phase of the FlowRun of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MoveOnRequest::class)))]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "phase".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is paused or the Flow is complete, the FlowRun is in another phase or its guided scene is no longer the current scene, the phase plays once, the next scene does not fit the session (200 scenes), or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: self::NOT_JSON, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function moveOn(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the phase as JSON.', self::MALFORMED_MOVE_ON);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $phase = $body['phase'] ?? null;
        if (!\is_string($phase)) {
            return $this->error(self::MALFORMED_MOVE_ON, Response::HTTP_BAD_REQUEST);
        }

        return $this->run(new MoveOn($campaignId, $user->id(), $phase), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/pause', name: 'api_campaigns_flow_run_pause', methods: ['POST'])]
    #[OA\Post(operationId: 'pauseGuidance', summary: 'Pause the guidance of the FlowRun of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is already paused or the Flow is complete, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function pause(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->run(new PauseGuidance($campaignId, $user->id()), $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/flow-run/resume', name: 'api_campaigns_flow_run_resume', methods: ['POST'])]
    #[OA\Post(operationId: 'resumeGuidance', summary: 'Resume the paused guidance of the FlowRun of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The campaign, with its FlowRun moved on.', content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)))]
    #[OA\Response(response: 401, description: self::NO_SESSION, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: self::NOT_A_SOLO_PLAYER, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: self::CAMPAIGN_NOT_FOUND, content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign plays freely, guidance is not paused or the Flow is complete, the next scene does not fit the session (200 scenes), or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function resume(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->run(new ResumeGuidance($campaignId, $user->id()), $campaignId, $user);
    }

    /**
     * Dispatches a FlowRun command, then answers with the refreshed campaign, or with the error.
     */
    private function run(Command $command, string $campaignId, AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);

            return new JsonResponse(CampaignResponse::fromView($this->queryBus->ask(new GetCampaign($campaignId, $user->id()))));
        } catch (CampaignNotFound|UnknownGameSystemOracle|UnknownCampaignTracker $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (FlowRunNotActive|FlowRunPositionMismatch|StepCannotBeSkipped|MoveOnNotAllowed|SceneTypeNotOffered|NoCurrentSession|CampaignLimitReached|CampaignModifiedConcurrently|JournalEntryAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidStepResult|ChaosFactorBoundToTracker|InvalidLikelihoodOracle|InvalidJournalEntryContent|InvalidDiceExpression $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
