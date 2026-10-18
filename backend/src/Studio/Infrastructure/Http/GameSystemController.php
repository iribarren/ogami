<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\EditGameSystemMetadata;
use App\Studio\Application\GameSystemIdGenerator;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\ValidateGameSystemDraft;
use App\Studio\Domain\GameSystem\GameSystemHasReleases;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\InvalidGameSystemId;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A game manager's GameSystem drafts (security.yaml restricts these routes to ROLE_GAME_MANAGER).
 * A draft belongs to its creator: another user's key is not found, exactly like an unknown one,
 * so a key's owner is not revealed.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemController
{
    use ReadsJsonBodies;

    private const string MALFORMED_CREATION = 'Send a JSON object with a string "key", a string "name" and, optionally, a string "description" and a "source" object with a string "key" and an integer "version", such as {"key": "my-system", "name": "My system", "source": {"key": "ironsworn"}}.';
    private const string MALFORMED_METADATA = 'Send a JSON object with a string "name" and, optionally, a string "description", a string "theme" and a "credits" object with a string "notice" and optional strings "license" and "url", such as {"name": "My system", "credits": {"notice": "Unofficial fan content."}}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private GameSystemIdGenerator $gameSystemIds,
    ) {
    }

    #[Route('/api/studio/game-systems', name: 'api_studio_game_systems_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listMyGameSystems', summary: 'List my GameSystem drafts')]
    #[OA\Response(
        response: 200,
        description: 'The signed-in game manager\'s GameSystems, the most recently updated first.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: GameSystemSummaryResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return new JsonResponse(array_map(
            GameSystemSummaryResponse::fromSummary(...),
            $this->queryBus->ask(new ListMyGameSystems($user->id())),
        ));
    }

    #[Route('/api/studio/game-systems', name: 'api_studio_game_systems_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createGameSystem', summary: 'Create a GameSystem draft, blank or as a copy of a published release')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateGameSystemRequest::class)))]
    #[OA\Response(
        response: 201,
        description: 'The new draft; the Location header is its URL.',
        headers: [new OA\Header(header: 'Location', description: 'The URL of the new GameSystem.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: new Model(type: GameSystemResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "key" or "name", or a "description", "source" or "source.version" of the wrong type.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another GameSystem or a release (a preset\'s included) already has the key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The key, name or description breaks the release contract\'s rules (the message names the field), or the source release does not exist.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the GameSystem as JSON.', self::MALFORMED_CREATION);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $key = $body['key'] ?? null;
        $name = $body['name'] ?? null;
        $description = $body['description'] ?? null;
        $source = $body['source'] ?? null;
        if (!\is_string($key) || !\is_string($name) || (null !== $description && !\is_string($description))) {
            return $this->error(self::MALFORMED_CREATION, Response::HTTP_BAD_REQUEST);
        }

        $sourceKey = null;
        $sourceVersion = null;
        if (null !== $source) {
            $sourceKey = $this->hasJsonObjectAt($request, 'source') && \is_array($source) ? ($source['key'] ?? null) : null;
            $sourceVersion = \is_array($source) ? ($source['version'] ?? null) : null;
            if (!\is_string($sourceKey) || (null !== $sourceVersion && !\is_int($sourceVersion))) {
                return $this->error(self::MALFORMED_CREATION, Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $this->commandBus->dispatch(new CreateGameSystem($this->gameSystemIds->generate()->toString(), $user->id(), $key, $name, $description, $sourceKey, $sourceVersion));
        } catch (InvalidGameSystemMetadata|InvalidGameSystemId|PublishedReleaseNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemKeyTaken $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $response = $this->gameSystem($user, $key, Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/studio/game-systems/'.rawurlencode($key));

        return $response;
    }

    #[Route('/api/studio/game-systems/{key}', name: 'api_studio_game_systems_get', methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystem', summary: 'Read one of my GameSystem drafts')]
    #[OA\Response(response: 200, description: 'The draft.', content: new OA\JsonContent(ref: new Model(type: GameSystemResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function get(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->gameSystem($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/metadata', name: 'api_studio_game_systems_metadata_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editGameSystemMetadata', summary: 'Replace the name, description, credits and theme of one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: EditGameSystemMetadataRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft with its new metadata.', content: new OA\JsonContent(ref: new Model(type: GameSystemResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of the wrong type (a "credits" that is not an object or has no string "notice" included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or credits or a theme are given for a library pack, which has neither.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A field breaks the release contract\'s rules (the message names it), or credits or a theme are given for a schema version 1 draft; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function editMetadata(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the metadata as JSON.', self::MALFORMED_METADATA);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $description = $body['description'] ?? null;
        $credits = $body['credits'] ?? null;
        $theme = $body['theme'] ?? null;
        if (!\is_string($name) || (null !== $description && !\is_string($description)) || (null !== $theme && !\is_string($theme)) || (null !== $credits && !$this->isCredits($request, $credits))) {
            return $this->error(self::MALFORMED_METADATA, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new EditGameSystemMetadata($user->id(), $key, $name, $description, $credits, $theme));

            return $this->gameSystem($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidGameSystemMetadata $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemModifiedConcurrently|LibraryPackRefuses $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }

    #[Route('/api/studio/game-systems/{key}', name: 'api_studio_game_systems_discard', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'discardGameSystem', summary: 'Delete one of my GameSystem drafts that was never published')]
    #[OA\Response(response: 204, description: 'The draft is gone.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The GameSystem has releases, which campaigns may play.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function discard(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DiscardGameSystem($user->id(), $key));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemHasReleases $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/studio/game-systems/{key}/validation', name: 'api_studio_game_systems_validation', methods: ['GET'])]
    #[OA\Get(operationId: 'validateGameSystem', summary: 'Check whether one of my GameSystem drafts would be accepted as a release')]
    #[OA\Response(response: 200, description: 'The verdict: the first error with its path when invalid, the authoring warnings when valid.', content: new OA\JsonContent(ref: new Model(type: GameSystemValidationResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function validation(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return new JsonResponse(GameSystemValidationResponse::fromValidation($this->queryBus->ask(new ValidateGameSystemDraft($user->id(), $key))));
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @throws GameSystemNotFound
     */
    private function gameSystem(AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return new JsonResponse(GameSystemResponse::fromView($this->queryBus->ask(new GetGameSystem($user->id(), $key))), $status);
    }

    /**
     * Whether the credits are a JSON object with a string "notice" and strings or nulls for "license"
     * and "url". Any other member is left to the release contract to refuse (422).
     *
     * @phpstan-assert-if-true array<mixed> $credits
     */
    private function isCredits(Request $request, mixed $credits): bool
    {
        return \is_array($credits)
            && $this->hasJsonObjectAt($request, 'credits')
            && \is_string($credits['notice'] ?? null)
            && \is_string($credits['license'] ?? '')
            && \is_string($credits['url'] ?? '');
    }
}
