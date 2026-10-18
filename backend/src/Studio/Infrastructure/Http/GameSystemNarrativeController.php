<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\DeleteNarrativeItem;
use App\Studio\Application\GetGameSystemNarrative;
use App\Studio\Application\SaveNarrativeItem;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\NarrativeItemNotFound;
use App\Studio\Domain\GameSystem\NarrativeKeyTaken;
use App\Studio\Domain\GameSystem\NarrativeSection;
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
 * The narrative sections of a game manager's GameSystem drafts (trackers, tags, fact slots, Scene
 * Types and flows): a read and one save and delete command per item, the same for every section
 * (security.yaml restricts these routes to ROLE_GAME_MANAGER). Like the drafts, they belong to their
 * creator: another user's key is not found. Every write carries the draft version the client read
 * and answers with the narrative as it is now, new version included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemNarrativeController
{
    use ReadsJsonBodies;

    /**
     * The section segments of the paths: the document keys in kebab case.
     */
    private const array SLUGS = ['trackers', 'tags', 'fact-slots', 'scene-types', 'flows'];
    private const string SLUG_PATTERN = 'trackers|tags|fact-slots|scene-types|flows';

    private const string MALFORMED_SAVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and an "item" object, such as {"expectedVersion": 3, "item": {"key": "gang", "label": "Gang"}}.';
    private const string MALFORMED_DELETE = 'Send the draft version you read as an integer "expectedVersion" query parameter, such as ?expectedVersion=3.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/narrative', name: 'api_studio_game_systems_narrative', methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystemNarrative', summary: 'Read the trackers, tags, fact slots, Scene Types and flows of one of my GameSystem drafts, with where each is used and what the pickers offer')]
    #[OA\Response(response: 200, description: 'The narrative sections as stored, in order, the catalog and the draft version. A schema version 1 draft has empty sections.', content: new OA\JsonContent(ref: new Model(type: GameSystemNarrativeResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function narrative(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->narrativeOf($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/narrative/{section}', name: 'api_studio_game_systems_narrative_create', requirements: ['section' => self::SLUG_PATTERN], methods: ['POST'])]
    #[OA\Post(operationId: 'createNarrativeItem', summary: 'Add a tracker, tag, fact slot, Scene Type or flow to one of my GameSystem drafts')]
    #[OA\Parameter(name: 'section', description: 'Which narrative section: trackers, tags, fact slots, Scene Types or flows.', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::SLUGS))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveNarrativeItemRequest::class)))]
    #[OA\Response(response: 201, description: 'The draft\'s narrative with the item appended and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemNarrativeResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "item" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the section is not one of those listed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the key is already used in the section. A library pack refuses flows.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The item breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), or the draft has schema version 1 (no narrative sections); nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(string $key, string $section, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, $section, null, $request, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/studio/game-systems/{key}/narrative/{section}/{item}', name: 'api_studio_game_systems_narrative_replace', requirements: ['section' => self::SLUG_PATTERN], methods: ['PUT'])]
    #[OA\Put(operationId: 'replaceNarrativeItem', summary: 'Replace a tracker, tag, fact slot, Scene Type or flow of one of my GameSystem drafts, renaming it when the body has another key')]
    #[OA\Parameter(name: 'section', description: 'Which narrative section: trackers, tags, fact slots, Scene Types or flows.', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::SLUGS))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveNarrativeItemRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s narrative with the item replaced in place and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemNarrativeResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "item" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, the section is not one of those listed, or it has no item with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the new key is already used in the section. A library pack refuses flows.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The item breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), the draft has schema version 1, or a rename is used by the draft (the paths say where); nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function replace(string $key, string $section, string $item, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, $section, $item, $request, $user, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/narrative/{section}/{item}', name: 'api_studio_game_systems_narrative_delete', requirements: ['section' => self::SLUG_PATTERN], methods: ['DELETE'])]
    #[OA\Delete(operationId: 'deleteNarrativeItem', summary: 'Delete a tracker, tag, fact slot, Scene Type or flow of one of my GameSystem drafts that nothing uses')]
    #[OA\Parameter(name: 'section', description: 'Which narrative section: trackers, tags, fact slots, Scene Types or flows.', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::SLUGS))]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s narrative without the item and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemNarrativeResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, the section is not one of those listed, or it has no item with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses flows.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft uses the item (the paths say where), or has schema version 1; nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function delete(string $key, string $section, string $item, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $request->query->filter('expectedVersion', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new DeleteNarrativeItem($user->id(), $key, $expectedVersion, $this->sectionOf($section), $item));

            return $this->narrativeOf($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound|NarrativeItemNotFound|GameSystemModifiedConcurrently|NarrativeItemInUse|LibraryPackRefuses|InvalidReleaseContent $exception) {
            return $this->refusal($exception);
        }
    }

    /**
     * Creates the item, or replaces the one named $originalKey.
     */
    private function save(string $key, string $section, ?string $originalKey, Request $request, AuthenticatedUser $user, int $status): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the item as JSON.', self::MALFORMED_SAVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $item = $body['item'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($item) || !$this->hasJsonObjectAt($request, 'item')) {
            return $this->error(self::MALFORMED_SAVE, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new SaveNarrativeItem($user->id(), $key, $expectedVersion, $this->sectionOf($section), $originalKey, $item));

            return $this->narrativeOf($user, $key, $status);
        } catch (GameSystemNotFound|NarrativeItemNotFound|GameSystemModifiedConcurrently|NarrativeKeyTaken|NarrativeItemInUse|LibraryPackRefuses|InvalidReleaseContent $exception) {
            return $this->refusal($exception);
        }
    }

    /**
     * @param string $slug one of the route's section segments
     */
    private function sectionOf(string $slug): NarrativeSection
    {
        return match ($slug) {
            'trackers' => NarrativeSection::Trackers,
            'tags' => NarrativeSection::Tags,
            'fact-slots' => NarrativeSection::FactSlots,
            'scene-types' => NarrativeSection::SceneTypes,
            'flows' => NarrativeSection::Flows,
            default => throw new \LogicException(\sprintf('The route requirement lets only %s through, not "%s".', self::SLUG_PATTERN, $slug)),
        };
    }

    /**
     * @throws GameSystemNotFound
     */
    private function narrativeOf(AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return new JsonResponse(GameSystemNarrativeResponse::fromView($this->queryBus->ask(new GetGameSystemNarrative($user->id(), $key))), $status);
    }

    private function refusal(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof GameSystemNotFound, $exception instanceof NarrativeItemNotFound => $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof GameSystemModifiedConcurrently, $exception instanceof NarrativeKeyTaken, $exception instanceof LibraryPackRefuses => $this->error($exception->getMessage(), Response::HTTP_CONFLICT),
            $exception instanceof NarrativeItemInUse => new JsonResponse(OracleInUseResponse::fromException($exception), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }
}
