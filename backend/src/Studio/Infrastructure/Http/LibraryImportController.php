<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\DetachLibraryImport;
use App\Studio\Application\GetGameSystemLibraryImports;
use App\Studio\Application\ImportLibraryItems;
use App\Studio\Application\LibraryPackNotFound;
use App\Studio\Application\PreviewLibraryImport;
use App\Studio\Application\UpdateLibraryImport;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryImportNotFound;
use App\Studio\Domain\GameSystem\LibraryItemKeyTaken;
use App\Studio\Domain\GameSystem\LibraryItemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRemoved;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\LibrarySection;
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
 * What a game manager's GameSystem drafts import from the library (ADR 0033; security.yaml restricts
 * these routes to ROLE_GAME_MANAGER): the imports and where they stand against their packs, a
 * preview, and one import, update and detach command. Like the drafts, they belong to their creator:
 * another user's key is not found. Every write carries the draft version the client read and answers
 * with the imports as they are now, new version included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class LibraryImportController
{
    use ReadsJsonBodies;

    /**
     * The section segments of the paths: the values of LibrarySection.
     */
    private const string SECTION_PATTERN = 'oracles|trackers|tags|fact-slots|scene-types';
    private const array SECTIONS = ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'];

    private const string MALFORMED_IMPORT = 'Send a JSON object with an integer "expectedVersion", the draft version you read, a string "pack" and a list of "items", such as {"expectedVersion": 3, "pack": "fantasy-basics", "items": [{"section": "scene-types", "key": "tavern"}]}.';
    private const string MALFORMED_PREVIEW = 'Send a JSON object with a string "pack" and a list of "items", such as {"pack": "fantasy-basics", "items": [{"section": "scene-types", "key": "tavern"}]}.';
    private const string MALFORMED_UPDATE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, such as {"expectedVersion": 3}.';
    private const string MALFORMED_DETACH = 'Send the draft version you read as an integer "expectedVersion" query parameter, such as ?expectedVersion=3.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/library-imports', name: 'api_studio_game_systems_library_imports_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listLibraryImports', summary: 'List what one of my GameSystem drafts imported from the library, with where each import stands against its pack')]
    #[OA\Response(response: 200, description: 'The imports in the order imported, and the draft version. A draft that imported nothing, a schema version 1 draft included, has none.', content: new OA\JsonContent(ref: new Model(type: LibraryImportsResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->importsOf($user, $key);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/library-imports/preview', name: 'api_studio_game_systems_library_imports_preview', methods: ['POST'])]
    #[OA\Post(operationId: 'previewLibraryImport', summary: 'Show what importing items of a library pack would do to one of my GameSystem drafts, without changing it')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PreviewLibraryImportRequest::class)))]
    #[OA\Response(response: 200, description: 'The items the import copies, the needed ones the draft keeps, the requested ones it already has, and the item rule the result would break.', content: new OA\JsonContent(ref: new Model(type: LibraryImportPreviewResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "pack".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, no library pack has the pack key (a GameSystem\'s key is not a pack), or the pack has none of an item.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The GameSystem is a library pack, which imports nothing.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The "items" are not a non-empty list of {section, key} objects (the message names the path), or the draft has schema version 1.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function preview(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the preview request as JSON.', self::MALFORMED_PREVIEW);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $pack = $body['pack'] ?? null;
        if (!\is_string($pack)) {
            return $this->error(self::MALFORMED_PREVIEW, Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(LibraryImportPreviewResponse::fromPreview($this->queryBus->ask(new PreviewLibraryImport($user->id(), $key, $pack, $body['items'] ?? null))));
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }
    }

    #[Route('/api/studio/game-systems/{key}/library-imports', name: 'api_studio_game_systems_library_imports_import', methods: ['POST'])]
    #[OA\Post(operationId: 'importLibraryItems', summary: 'Copy items of a library pack, and the pack items they need, into one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ImportLibraryItemsRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s imports with the new ones appended and the new version.', content: new OA\JsonContent(ref: new Model(type: LibraryImportsResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no string "pack".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, no library pack has the pack key (a GameSystem\'s key is not a pack), or the pack has none of an item.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the draft already has a requested item (the message lists every one), or the GameSystem is a library pack, which imports nothing.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The "items" are not a non-empty list of {section, key} objects, the result would break the release contract\'s item rules (the message starts with the path), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function import(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the import as JSON.', self::MALFORMED_IMPORT);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $pack = $body['pack'] ?? null;
        if (!\is_int($expectedVersion) || !\is_string($pack)) {
            return $this->error(self::MALFORMED_IMPORT, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ImportLibraryItems($user->id(), $key, $expectedVersion, $pack, $body['items'] ?? null));

            return $this->importsOf($user, $key);
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }
    }

    #[Route('/api/studio/game-systems/{key}/library-imports/{section}/{item}/update', name: 'api_studio_game_systems_library_imports_update', requirements: ['section' => self::SECTION_PATTERN], methods: ['POST'])]
    #[OA\Post(operationId: 'updateLibraryImport', summary: 'Replace an imported item of one of my GameSystem drafts with the library pack\'s current one')]
    #[OA\Parameter(name: 'section', description: 'Which section the item is in: oracles, trackers, tags, fact slots or Scene Types.', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::SECTIONS))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateLibraryImportRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s imports, the updated one current again, and the new version.', content: new OA\JsonContent(ref: new Model(type: LibraryImportsResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "expectedVersion".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, the section is not one of those listed, or the draft did not import the item.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), the pack or its item is gone, or the GameSystem is a library pack, which imports nothing.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The result would break the release contract\'s item rules (the message starts with the path), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function update(string $key, string $section, string $item, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the update as JSON.', self::MALFORMED_UPDATE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_UPDATE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new UpdateLibraryImport($user->id(), $key, $expectedVersion, LibrarySection::from($section), $item));

            return $this->importsOf($user, $key);
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }
    }

    #[Route('/api/studio/game-systems/{key}/library-imports/{section}/{item}', name: 'api_studio_game_systems_library_imports_detach', requirements: ['section' => self::SECTION_PATTERN], methods: ['DELETE'])]
    #[OA\Delete(operationId: 'detachLibraryImport', summary: 'Forget that an item of one of my GameSystem drafts came from the library; the item stays')]
    #[OA\Parameter(name: 'section', description: 'Which section the item is in: oracles, trackers, tags, fact slots or Scene Types.', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::SECTIONS))]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s imports without the item and the new version.', content: new OA\JsonContent(ref: new Model(type: LibraryImportsResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, the section is not one of those listed, or the draft did not import the item.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the GameSystem is a library pack, which imports nothing.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function detach(string $key, string $section, string $item, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $request->query->filter('expectedVersion', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DETACH, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new DetachLibraryImport($user->id(), $key, $expectedVersion, LibrarySection::from($section), $item));

            return $this->importsOf($user, $key);
        } catch (\Throwable $exception) {
            return $this->refusal($exception);
        }
    }

    /**
     * @throws GameSystemNotFound
     */
    private function importsOf(AuthenticatedUser $user, string $key): JsonResponse
    {
        return new JsonResponse(LibraryImportsResponse::fromView($this->queryBus->ask(new GetGameSystemLibraryImports($user->id(), $key))));
    }

    private function refusal(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof GameSystemNotFound, $exception instanceof LibraryPackNotFound, $exception instanceof LibraryItemNotFound, $exception instanceof LibraryImportNotFound => $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof GameSystemModifiedConcurrently, $exception instanceof LibraryItemKeyTaken, $exception instanceof LibraryItemRemoved, $exception instanceof LibraryPackRefuses => $this->error($exception->getMessage(), Response::HTTP_CONFLICT),
            $exception instanceof InvalidReleaseContent => $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => throw $exception,
        };
    }
}
