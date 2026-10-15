<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemRelease;
use App\Studio\Application\GetPublishedReleaseById;
use App\Studio\Application\ListGameSystemReleases;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\ReleaseIdGenerator;
use App\Studio\Application\RestoreGameSystemRelease;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\InvalidGameSystemRelease;
use App\Studio\Domain\Release\InvalidReleaseContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Publishing a GameSystem draft, its release history and restoring a release into the draft
 * (security.yaml restricts these routes to ROLE_GAME_MANAGER). Another user's key is not found,
 * exactly like an unknown one.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemReleaseController
{
    use ReadsJsonBodies;

    private const string MALFORMED_PUBLISH = 'Send no body, or a JSON object with, optionally, a string "notes", such as {"notes": "Rebalanced the oracles."}.';
    private const string MALFORMED_RESTORE = 'Send a JSON object with an integer "version", such as {"version": 2}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private ReleaseIdGenerator $releaseIds,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/releases', name: 'api_studio_game_systems_releases_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listGameSystemReleases', summary: 'List the releases of one of my GameSystems, the newest first')]
    #[OA\Response(response: 200, description: 'The release history; empty when it was never published.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: GameSystemReleaseSummaryResponse::class))))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return new JsonResponse(array_map(
                GameSystemReleaseSummaryResponse::fromSummary(...),
                $this->queryBus->ask(new ListGameSystemReleases($user->id(), $key)),
            ));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/releases', name: 'api_studio_game_systems_releases_publish', methods: ['POST'])]
    #[OA\Post(operationId: 'publishGameSystem', summary: 'Publish my GameSystem draft as its next release')]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: PublishGameSystemRequest::class)))]
    #[OA\Response(
        response: 201,
        description: 'The new release; the Location header is its URL.',
        headers: [new OA\Header(header: 'Location', description: 'The URL of the new release.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: new Model(type: GameSystemReleaseResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has "notes" that is not a string.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The draft has no changes since the latest release, or another publish took the version first; nothing was published.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'A body was sent and it is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft breaks the release contract (the message names the path of the first error) or the notes are blank, contain NUL or invalid UTF-8, or are longer than 2,000 characters; nothing was published.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function publish(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = '' === $request->getContent() ? [] : $this->jsonBody($request, 'Send the release notes as JSON.', self::MALFORMED_PUBLISH);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $notes = $body['notes'] ?? null;
        if ((null !== $notes && !\is_string($notes)) || ([] !== $body && array_is_list($body))) {
            return $this->error(self::MALFORMED_PUBLISH, Response::HTTP_BAD_REQUEST);
        }

        $releaseId = $this->releaseIds->generate()->toString();

        try {
            $this->commandBus->dispatch(new PublishGameSystemDraft($user->id(), $key, $releaseId, $notes));
            $published = $this->queryBus->ask(new GetPublishedReleaseById($releaseId));
            \assert($published instanceof PublishedReleaseView);
            $response = new JsonResponse(GameSystemReleaseResponse::fromView($this->queryBus->ask(new GetGameSystemRelease($user->id(), $key, $published->version))), Response::HTTP_CREATED);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidReleaseContent|InvalidGameSystemRelease $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemDraftUnchanged|GameSystemReleaseAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $response->headers->set('Location', \sprintf('/api/studio/game-systems/%s/releases/%d', rawurlencode($key), $published->version));

        return $response;
    }

    #[Route('/api/studio/game-systems/{key}/releases/{version}', name: 'api_studio_game_systems_releases_get', requirements: ['version' => '\d{1,9}'], methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystemRelease', summary: 'Read one release of one of my GameSystems')]
    #[OA\Response(response: 200, description: 'The release.', content: new OA\JsonContent(ref: new Model(type: GameSystemReleaseResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no such release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function get(string $key, int $version, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return new JsonResponse(GameSystemReleaseResponse::fromView($this->queryBus->ask(new GetGameSystemRelease($user->id(), $key, $version))));
        } catch (GameSystemNotFound|GameSystemReleaseNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/restore', name: 'api_studio_game_systems_restore', methods: ['POST'])]
    #[OA\Post(operationId: 'restoreGameSystemRelease', summary: 'Replace my GameSystem draft with the content of one of its releases')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RestoreGameSystemRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft with the release\'s content.', content: new OA\JsonContent(ref: new Model(type: GameSystemResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "version".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The GameSystem has no release with that version; the draft is unchanged.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function restore(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the version as JSON.', self::MALFORMED_RESTORE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $version = $body['version'] ?? null;
        if (!\is_int($version)) {
            return $this->error(self::MALFORMED_RESTORE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new RestoreGameSystemRelease($user->id(), $key, $version));

            return new JsonResponse(GameSystemResponse::fromView($this->queryBus->ask(new GetGameSystem($user->id(), $key))));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }
}
