<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\CreateLibraryPack;
use App\Studio\Application\GameSystemIdGenerator;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetLibraryPack;
use App\Studio\Application\LibraryPackNotFound;
use App\Studio\Application\ListLibraryPacks;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\InvalidGameSystemId;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use Doctrine\DBAL\Connection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The library of packs (ADR 0033; security.yaml restricts these routes to ROLE_GAME_MANAGER). Every
 * game manager lists and reads every pack; only its author edits or deletes one, through the
 * GameSystem endpoints, where another user's pack is not found.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class LibraryPackController
{
    use ReadsJsonBodies;

    private const string MALFORMED_CREATION = 'Send a JSON object with a string "key", a string "name" and, optionally, a string "description", such as {"key": "fantasy-basics", "name": "Fantasy basics", "description": "Names, rumours and a tavern."}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private GameSystemIdGenerator $gameSystemIds,
        private Connection $connection,
    ) {
    }

    #[Route('/api/studio/library/packs', name: 'api_studio_library_packs_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listLibraryPacks', summary: 'List every library pack, whoever wrote it')]
    #[OA\Response(
        response: 200,
        description: 'Every pack: the signed-in game manager\'s first, then by name.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: LibraryPackSummaryResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return new JsonResponse(array_map(
            LibraryPackSummaryResponse::fromSummary(...),
            $this->queryBus->ask(new ListLibraryPacks($user->id())),
        ));
    }

    #[Route('/api/studio/library/packs', name: 'api_studio_library_packs_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createLibraryPack', summary: 'Create a blank library pack')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateLibraryPackRequest::class)))]
    #[OA\Response(
        response: 201,
        description: 'The new pack, as the GameSystem endpoints show it (kind library-pack); the Location header is its catalog URL.',
        headers: [new OA\Header(header: 'Location', description: 'The URL of the pack\'s catalog.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: new Model(type: GameSystemResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "key" or "name", or a "description" of the wrong type.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'A GameSystem, another pack or a release already has the key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The key, name or description breaks the release contract\'s rules (the message names the field).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the pack as JSON.', self::MALFORMED_CREATION);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $key = $body['key'] ?? null;
        $name = $body['name'] ?? null;
        $description = $body['description'] ?? null;
        if (!\is_string($key) || !\is_string($name) || (null !== $description && !\is_string($description))) {
            return $this->error(self::MALFORMED_CREATION, Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->connection->transactional(function () use ($user, $key, $name, $description): JsonResponse {
                $this->commandBus->dispatch(new CreateLibraryPack($this->gameSystemIds->generate()->toString(), $user->id(), $key, $name, $description));

                return new JsonResponse(GameSystemResponse::fromView($this->queryBus->ask(new GetGameSystem($user->id(), $key))), Response::HTTP_CREATED);
            });
        } catch (InvalidGameSystemMetadata|InvalidGameSystemId $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemKeyTaken $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $response->headers->set('Location', '/api/studio/library/packs/'.rawurlencode($key));

        return $response;
    }

    #[Route('/api/studio/library/packs/{pack}', name: 'api_studio_library_packs_get', methods: ['GET'])]
    #[OA\Get(operationId: 'getLibraryPack', summary: 'Browse one library pack, whoever wrote it')]
    #[OA\Response(response: 200, description: 'The pack\'s items by section, with what each one names.', content: new OA\JsonContent(ref: new Model(type: LibraryPackCatalogResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No library pack has this key (a GameSystem\'s key is not a pack).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function get(string $pack, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return new JsonResponse(LibraryPackCatalogResponse::fromCatalog($this->queryBus->ask(new GetLibraryPack($user->id(), $pack))));
        } catch (LibraryPackNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }
}
