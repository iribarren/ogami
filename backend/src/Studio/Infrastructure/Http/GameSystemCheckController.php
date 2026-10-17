<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\DeleteCheck;
use App\Studio\Application\GetGameSystemChecks;
use App\Studio\Application\MoveCheck;
use App\Studio\Application\SaveCheck;
use App\Studio\Domain\GameSystem\CheckInUse;
use App\Studio\Domain\GameSystem\CheckKeyTaken;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
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
 * The checks of a game manager's GameSystem drafts: a read and one create, replace, delete and move
 * command per check (security.yaml restricts these routes to ROLE_GAME_MANAGER). Like the drafts,
 * they belong to their creator: another user's key is not found. Every write carries the draft
 * version the client read and answers with the checks as they are now, new version included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemCheckController
{
    use ReadsJsonBodies;

    private const string MALFORMED_SAVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and a "check" object, such as {"expectedVersion": 3, "check": {"key": "skill", "label": "Skill", "dice": "1d10", "bands": [{"key": "miss", "label": "Miss", "upTo": 7}, {"key": "hit", "label": "Hit"}]}}.';
    private const string MALFORMED_MOVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and an integer "index", the check\'s new position, such as {"expectedVersion": 3, "index": 0}.';
    private const string MALFORMED_DELETE = 'Send the draft version you read as an integer "expectedVersion" query parameter, such as ?expectedVersion=3.';
    private const string CHANGED_AFTER_SAVE = 'The draft changed after this request saved it; reload it.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/checks', name: 'api_studio_game_systems_checks', methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystemChecks', summary: 'Read the checks of one of my GameSystem drafts, what their formulas may read and where each is used')]
    #[OA\Response(response: 200, description: 'The checks as stored, in order, the sheet fields and trackers a formula may read, the check steps that name each check, and the draft version. A schema version 1 draft has no checks.', content: new OA\JsonContent(ref: new Model(type: GameSystemChecksResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function checks(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->checksOf($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/checks', name: 'api_studio_game_systems_checks_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createCheck', summary: 'Add a check to one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveCheckRequest::class)))]
    #[OA\Response(response: 201, description: 'The draft\'s checks with the check appended and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemChecksResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "check" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the key names another check.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The check breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), or the draft has schema version 1 (no checks); nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, null, $request, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/studio/game-systems/{key}/checks/{check}', name: 'api_studio_game_systems_checks_replace', methods: ['PUT'])]
    #[OA\Put(operationId: 'replaceCheck', summary: 'Replace a check of one of my GameSystem drafts, renaming it when the body has another key')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveCheckRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s checks with the check replaced in place and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemChecksResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "check" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no check with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the new key names another check.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The check breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), the draft has schema version 1, or a step names the check being renamed or a band the check drops (the paths say where); nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function replace(string $key, string $check, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, $check, $request, $user, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/checks/{check}', name: 'api_studio_game_systems_checks_delete', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'deleteCheck', summary: 'Delete a check of one of my GameSystem drafts that no check step names')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s checks without the check and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemChecksResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no check with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A check step names the check (the paths say where), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function delete(string $key, string $check, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $this->expectedVersionParameter($request);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new DeleteCheck($user->id(), $key, $expectedVersion, $check), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/checks/{check}/move', name: 'api_studio_game_systems_checks_move', methods: ['POST'])]
    #[OA\Post(operationId: 'moveCheck', summary: 'Move a check of one of my GameSystem drafts to another position of the check list')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MoveCheckRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s checks with the check at its new position and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemChecksResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, or has no integer "expectedVersion" or no integer "index".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no check with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: '"index" is outside the check list (the message starts with "index"), the draft breaks the release contract\'s rules elsewhere (the message starts with the path), or it has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function move(string $key, string $check, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the new position as JSON.', self::MALFORMED_MOVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $index = $body['index'] ?? null;
        if (!\is_int($expectedVersion) || !\is_int($index)) {
            return $this->error(self::MALFORMED_MOVE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new MoveCheck($user->id(), $key, $expectedVersion, $check, $index), $user, $key, Response::HTTP_OK);
    }

    /**
     * Creates the check, or replaces the one named $originalKey.
     */
    private function save(string $key, ?string $originalKey, Request $request, AuthenticatedUser $user, int $status): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the check as JSON.', self::MALFORMED_SAVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $check = $body['check'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($check) || !$this->hasJsonObjectAt($request, 'check')) {
            return $this->error(self::MALFORMED_SAVE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new SaveCheck($user->id(), $key, $expectedVersion, $originalKey, $check), $user, $key, $status);
    }

    /**
     * Dispatches a check command and answers with the checks as they are now. The refusals of the
     * command map as usual; a draft that is gone when the checks are read back was changed after
     * the command saved it, which is a conflict, not a missing draft.
     */
    private function write(Command $command, AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (GameSystemNotFound|CheckNotFound|GameSystemModifiedConcurrently|CheckKeyTaken|CheckInUse|InvalidReleaseContent $exception) {
            return $this->refusal($exception);
        }

        try {
            return $this->checksOf($user, $key, $status);
        } catch (GameSystemNotFound) {
            return $this->error(self::CHANGED_AFTER_SAVE, Response::HTTP_CONFLICT);
        }
    }

    /**
     * The "expectedVersion" query parameter of a DELETE, which has no body; null unless it is one
     * integer (an array-shaped parameter, a missing or an empty one is not).
     */
    private function expectedVersionParameter(Request $request): ?int
    {
        $raw = $request->query->all()['expectedVersion'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }

        $version = filter_var($raw, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);

        return \is_int($version) ? $version : null;
    }

    /**
     * @throws GameSystemNotFound
     */
    private function checksOf(AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return new JsonResponse(GameSystemChecksResponse::fromView($this->queryBus->ask(new GetGameSystemChecks($user->id(), $key))), $status);
    }

    private function refusal(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof GameSystemNotFound, $exception instanceof CheckNotFound => $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof GameSystemModifiedConcurrently, $exception instanceof CheckKeyTaken => $this->error($exception->getMessage(), Response::HTTP_CONFLICT),
            $exception instanceof CheckInUse => new JsonResponse(OracleInUseResponse::fromException($exception), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }
}
