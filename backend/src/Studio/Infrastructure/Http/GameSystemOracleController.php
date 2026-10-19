<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Infrastructure\Http\OracleTableResultResponse;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\DeleteLikelihoodOracle;
use App\Studio\Application\DeleteOracleTable;
use App\Studio\Application\GetGameSystemOracles;
use App\Studio\Application\SaveLikelihoodOracle;
use App\Studio\Application\SaveOracleTable;
use App\Studio\Application\TestAskLikelihoodOracle;
use App\Studio\Application\TestRollOracleTable;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\OracleInUse;
use App\Studio\Domain\GameSystem\OracleKeyTaken;
use App\Studio\Domain\GameSystem\OracleNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
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
 * The oracles of a game manager's GameSystem drafts: a read, the oracle table and likelihood oracle
 * commands, and test rolls and test answers (security.yaml restricts these routes to ROLE_GAME_MANAGER). Like the drafts, they belong
 * to their creator: another user's key is not found. Every write carries the draft version the
 * client read and answers with the oracles as they are now, new version included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemOracleController
{
    use ReadsJsonBodies;

    private const string MALFORMED_SAVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and a "table" object, such as {"expectedVersion": 3, "table": {"key": "rumours", "name": "Rumours", "entries": [{"text": "True"}]}}.';
    private const string MALFORMED_SAVE_LIKELIHOOD = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and an "oracle" object, such as {"expectedVersion": 3, "oracle": {"key": "fate", "name": "Fate", "sides": 100, "levels": [{"key": "even", "label": "50/50", "target": 50}]}}.';
    private const string MALFORMED_TEST_ANSWER = 'Send a JSON object with a string "likelihood" and an optional integer "chaosFactor", such as {"likelihood": "likely", "chaosFactor": 5}.';
    private const string MALFORMED_DELETE = 'Send the draft version you read as an integer "expectedVersion" query parameter, such as ?expectedVersion=3.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private Connection $connection,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/oracles', name: 'api_studio_game_systems_oracles', methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystemOracles', summary: 'Read the oracle tables and likelihood oracles of one of my GameSystem drafts, with where each is used')]
    #[OA\Response(response: 200, description: 'The oracles as stored, in order, and the draft version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function oracles(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->oraclesOf($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/oracle-tables', name: 'api_studio_game_systems_oracle_tables_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createOracleTable', summary: 'Add an oracle table to one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveOracleTableRequest::class)))]
    #[OA\Response(response: 201, description: 'The draft\'s oracles with the table appended and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "table" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the key is already used by another table or likelihood oracle.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The table breaks the release contract\'s rules (the message starts with the path), or has schema version 2 fields on a version 1 draft; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, null, $request, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/studio/game-systems/{key}/oracle-tables/{table}', name: 'api_studio_game_systems_oracle_tables_replace', methods: ['PUT'])]
    #[OA\Put(operationId: 'replaceOracleTable', summary: 'Replace an oracle table of one of my GameSystem drafts, renaming it when the body has another key')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveOracleTableRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s oracles with the table replaced in place and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "table" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no table with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the new key is already used by another table or likelihood oracle.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The table breaks the release contract\'s rules (the message starts with the path), has schema version 2 fields on a version 1 draft, or a rename or a dropped entry key is used by the draft (the paths say where); nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function replace(string $key, string $table, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, $table, $request, $user, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/oracle-tables/{table}', name: 'api_studio_game_systems_oracle_tables_delete', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'deleteOracleTable', summary: 'Delete an oracle table of one of my GameSystem drafts that nothing uses')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s oracles without the table and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no table with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft uses the table (the paths say where); nothing changed.', content: new OA\JsonContent(ref: new Model(type: OracleInUseResponse::class)))]
    public function delete(string $key, string $table, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $request->query->filter('expectedVersion', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->writeThenRead(new DeleteOracleTable($user->id(), $key, $table, $expectedVersion), $user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound|OracleNotFound|GameSystemModifiedConcurrently|OracleInUse $exception) {
            return $this->refusal($exception);
        }
    }

    #[Route('/api/studio/game-systems/{key}/oracle-tables/{table}/test-rolls', name: 'api_studio_game_systems_oracle_tables_test_roll', methods: ['POST'])]
    #[OA\Post(operationId: 'testRollOracleTable', summary: 'Roll on an oracle table of one of my GameSystem drafts as last saved, following nested tables; never changes the draft')]
    #[OA\Response(response: 200, description: 'Every table rolled on, root first, with the dice, total and selected entry.', content: new OA\JsonContent(ref: new Model(type: OracleTableResultResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no table with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The saved tables are not valid for Randomness (a nested table is missing, a range is uncovered…) or the roll matches no entry.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function testRoll(string $key, string $table, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return new JsonResponse(OracleTableResultResponse::fromView($this->queryBus->ask(new TestRollOracleTable($user->id(), $key, $table))));
        } catch (GameSystemNotFound|OracleNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidOracleTable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/api/studio/game-systems/{key}/likelihood-oracles', name: 'api_studio_game_systems_likelihood_oracles_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createLikelihoodOracle', summary: 'Add a likelihood oracle to one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveLikelihoodOracleRequest::class)))]
    #[OA\Response(response: 201, description: 'The draft\'s oracles with the likelihood oracle appended and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "oracle" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the key is already used by another table or likelihood oracle.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The oracle breaks the release contract\'s rules (the message starts with the path), or has schema version 2 fields on a version 1 draft; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function createLikelihood(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->saveLikelihood($key, null, $request, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/studio/game-systems/{key}/likelihood-oracles/{oracle}', name: 'api_studio_game_systems_likelihood_oracles_replace', methods: ['PUT'])]
    #[OA\Put(operationId: 'replaceLikelihoodOracle', summary: 'Replace a likelihood oracle of one of my GameSystem drafts, renaming it when the body has another key')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveLikelihoodOracleRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s oracles with the likelihood oracle replaced in place and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "oracle" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no likelihood oracle with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the new key is already used by another table or likelihood oracle.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The oracle breaks the release contract\'s rules (the message starts with the path), has schema version 2 fields on a version 1 draft, or a rename is used by the draft (the paths say where); nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function replaceLikelihood(string $key, string $oracle, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->saveLikelihood($key, $oracle, $request, $user, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/likelihood-oracles/{oracle}', name: 'api_studio_game_systems_likelihood_oracles_delete', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'deleteLikelihoodOracle', summary: 'Delete a likelihood oracle of one of my GameSystem drafts that nothing uses')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s oracles without the likelihood oracle and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemOraclesResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no likelihood oracle with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft uses the oracle (the paths say where); nothing changed.', content: new OA\JsonContent(ref: new Model(type: OracleInUseResponse::class)))]
    public function deleteLikelihood(string $key, string $oracle, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $request->query->filter('expectedVersion', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->writeThenRead(new DeleteLikelihoodOracle($user->id(), $key, $oracle, $expectedVersion), $user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound|OracleNotFound|GameSystemModifiedConcurrently|OracleInUse $exception) {
            return $this->refusal($exception);
        }
    }

    #[Route('/api/studio/game-systems/{key}/likelihood-oracles/{oracle}/test-answers', name: 'api_studio_game_systems_likelihood_oracles_test_answer', methods: ['POST'])]
    #[OA\Post(operationId: 'testAskLikelihoodOracle', summary: 'Ask a likelihood oracle of one of my GameSystem drafts as last saved, rolling its random event table on a random event; never changes the draft')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: TestAnswerRequest::class)))]
    #[OA\Response(response: 200, description: 'The answer, the roll and the effective target, and the random event roll when there was one.', content: new OA\JsonContent(ref: new Model(type: LikelihoodTestAnswerResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "likelihood", or a non-integer "chaosFactor".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or it has no likelihood oracle with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The saved oracle is not valid for Randomness, it has no such likelihood level, the chaos factor is out of range or given without chaos, or the random event tables are invalid or the roll matches no entry.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function testAnswer(string $key, string $oracle, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the question as JSON.', self::MALFORMED_TEST_ANSWER);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $likelihood = $body['likelihood'] ?? null;
        $chaosFactor = $body['chaosFactor'] ?? null;
        if (!\is_string($likelihood) || (null !== $chaosFactor && !\is_int($chaosFactor))) {
            return $this->error(self::MALFORMED_TEST_ANSWER, Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(LikelihoodTestAnswerResponse::fromView($this->queryBus->ask(new TestAskLikelihoodOracle($user->id(), $key, $oracle, $likelihood, $chaosFactor))));
        } catch (GameSystemNotFound|OracleNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidLikelihoodOracle|InvalidOracleTable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Creates the table, or replaces the one named $originalKey.
     */
    private function save(string $key, ?string $originalKey, Request $request, AuthenticatedUser $user, int $status): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the table as JSON.', self::MALFORMED_SAVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $table = $body['table'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($table) || !$this->hasJsonObjectAt($request, 'table')) {
            return $this->error(self::MALFORMED_SAVE, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->writeThenRead(new SaveOracleTable($user->id(), $key, $originalKey, $table, $expectedVersion), $user, $key, $status);
        } catch (GameSystemNotFound|OracleNotFound|GameSystemModifiedConcurrently|OracleKeyTaken|OracleInUse|InvalidReleaseContent $exception) {
            return $this->refusal($exception);
        }
    }

    /**
     * Creates the likelihood oracle, or replaces the one named $originalKey.
     */
    private function saveLikelihood(string $key, ?string $originalKey, Request $request, AuthenticatedUser $user, int $status): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the oracle as JSON.', self::MALFORMED_SAVE_LIKELIHOOD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $oracle = $body['oracle'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($oracle) || !$this->hasJsonObjectAt($request, 'oracle')) {
            return $this->error(self::MALFORMED_SAVE_LIKELIHOOD, Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->writeThenRead(new SaveLikelihoodOracle($user->id(), $key, $originalKey, $oracle, $expectedVersion), $user, $key, $status);
        } catch (GameSystemNotFound|OracleNotFound|GameSystemModifiedConcurrently|OracleKeyTaken|OracleInUse|InvalidReleaseContent $exception) {
            return $this->refusal($exception);
        }
    }

    /**
     * Runs the command and reads the oracles back in one transaction. The command's UPDATE holds the
     * draft's row until the commit, so the read sees exactly this write, never a save that
     * interleaved, and a read that fails rolls the write back, so the error answered is honest. The
     * command bus's own transaction nests inside this one, and a refusal rolls both back.
     */
    private function writeThenRead(Command $command, AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return $this->connection->transactional(function () use ($command, $user, $key, $status): JsonResponse {
            $this->commandBus->dispatch($command);

            return $this->oraclesOf($user, $key, $status);
        });
    }

    /**
     * @throws GameSystemNotFound
     */
    private function oraclesOf(AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return new JsonResponse(GameSystemOraclesResponse::fromView($this->queryBus->ask(new GetGameSystemOracles($user->id(), $key))), $status);
    }

    private function refusal(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof GameSystemNotFound, $exception instanceof OracleNotFound => $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof GameSystemModifiedConcurrently, $exception instanceof OracleKeyTaken => $this->error($exception->getMessage(), Response::HTTP_CONFLICT),
            $exception instanceof OracleInUse => new JsonResponse(OracleInUseResponse::fromException($exception), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }
}
