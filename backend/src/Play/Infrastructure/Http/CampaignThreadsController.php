<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\AddThread;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CloseThread;
use App\Play\Application\EditThread;
use App\Play\Application\GetCampaign;
use App\Play\Application\ReopenThread;
use App\Play\Application\ThreadIdGenerator;
use App\Play\Application\ThreadView;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
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
 * The Threads of a solo player's campaigns: add, edit, close and reopen (security.yaml restricts these
 * routes to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly like an unknown
 * one (the campaign is checked first), and so is a Thread id the campaign does not hold, such as one
 * that is not a UUID. The Tags come from the campaign's pinned release.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignThreadsController
{
    use ReadsJsonBodies;

    private const string MALFORMED_THREAD = 'Send a JSON object with a string "name" and, optionally, lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Rob the vault", "tags": ["heist"], "weight": 2}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private ThreadIdGenerator $threadIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/threads', name: 'api_campaigns_threads_add', methods: ['POST'])]
    #[OA\Post(operationId: 'addThread', summary: 'Add a Thread to one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ThreadRequest::class)))]
    #[OA\Response(response: 201, description: 'The new, open Thread.', content: new OA\JsonContent(ref: new Model(type: ThreadResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 500 Threads, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function add(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        $threadId = $this->threadIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new AddThread($campaignId, $user->id(), $threadId, ...$fields));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|ThreadAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidThread|UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->thread($campaignId, $threadId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/threads/{threadId}', name: 'api_campaigns_threads_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editThread', summary: 'Replace the name, Tags, free tags and weight of a Thread of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ThreadRequest::class)))]
    #[OA\Response(response: 200, description: 'The edited Thread; its status stays.', content: new OA\JsonContent(ref: new Model(type: ThreadResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Thread with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function edit(string $campaignId, string $threadId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $this->commandBus->dispatch(new EditThread($campaignId, $user->id(), $threadId, ...$fields));
        } catch (CampaignNotFound|UnknownThread $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidThread $exception) {
            // A blank id is not a value to fix: no Thread has it.
            return '' === trim($threadId) ? $this->blankThreadNotFound($threadId) : $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->thread($campaignId, $threadId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/threads/{threadId}/close', name: 'api_campaigns_threads_close', methods: ['POST'])]
    #[OA\Post(operationId: 'closeThread', summary: 'Close a Thread of one of my campaigns: flows no longer pick or count it')]
    #[OA\Response(response: 200, description: 'The closed Thread. Closing a closed one changes nothing.', content: new OA\JsonContent(ref: new Model(type: ThreadResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Thread with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function close(string $campaignId, string $threadId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new CloseThread($campaignId, $user->id(), $threadId));
        } catch (CampaignNotFound|UnknownThread $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidThread) {
            return $this->blankThreadNotFound($threadId);
        }

        return $this->thread($campaignId, $threadId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/threads/{threadId}/reopen', name: 'api_campaigns_threads_reopen', methods: ['POST'])]
    #[OA\Post(operationId: 'reopenThread', summary: 'Reopen a Thread of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The reopened Thread. Reopening an open one changes nothing.', content: new OA\JsonContent(ref: new Model(type: ThreadResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Thread with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function reopen(string $campaignId, string $threadId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ReopenThread($campaignId, $user->id(), $threadId));
        } catch (CampaignNotFound|UnknownThread $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidThread) {
            return $this->blankThreadNotFound($threadId);
        }

        return $this->thread($campaignId, $threadId, $user, Response::HTTP_OK);
    }

    /**
     * Reads the Thread fields of the body: a string name, lists of
     * strings or null tags and free tags and an integer or null weight. A field of another JSON
     * type is a 400, never a silent default.
     *
     * @return array{name: string, tags: list<string>, freeTags: list<string>, weight: int}|JsonResponse the fields as the commands take them (omitted ones defaulted), or the error response to send
     */
    private function fields(Request $request): array|JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the Thread as JSON.', self::MALFORMED_THREAD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $tags = $body['tags'] ?? [];
        $freeTags = $body['freeTags'] ?? [];
        $weight = $body['weight'] ?? 1;
        if (!\is_string($name) || !$this->isListOfStrings($tags) || !$this->isListOfStrings($freeTags) || !\is_int($weight)) {
            return $this->error(self::MALFORMED_THREAD, Response::HTTP_BAD_REQUEST);
        }

        return ['name' => $name, 'tags' => $tags, 'freeTags' => $freeTags, 'weight' => $weight];
    }

    /**
     * A blank id (all spaces in the path) cannot be a Thread id, so no campaign holds that Thread.
     */
    private function blankThreadNotFound(string $threadId): JsonResponse
    {
        return $this->error(\sprintf('Thread "%s" not found.', $threadId), Response::HTTP_NOT_FOUND);
    }

    /**
     * @throws CampaignNotFound
     */
    private function thread(string $campaignId, string $threadId, AuthenticatedUser $user, int $status): JsonResponse
    {
        $threads = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->threads;
        $thread = array_find($threads, static fn (ThreadView $thread): bool => $thread->id === $threadId) ?? throw new \LogicException('The Thread was just saved.');

        return new JsonResponse(ThreadResponse::fromView($thread), $status);
    }
}
