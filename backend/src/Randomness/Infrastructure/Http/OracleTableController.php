<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\ResolveOracleTable;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rolls on oracle tables sent with the request, for any signed-in user. Results are not stored.
 */
#[AsController]
#[OA\Tag(name: 'Oracles')]
final readonly class OracleTableController
{
    private const string MALFORMED_BODY = 'Send a JSON object with a "tables" list and a string "table", such as {"tables": [{"key": "weather", …}], "table": "weather"}.';

    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/oracle-table-results', name: 'api_oracle_table_results_create', methods: ['POST'])]
    #[OA\Post(operationId: 'resolveOracleTable', summary: 'Roll on an oracle table, following nested tables')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: OracleTableResultRequest::class)))]
    #[OA\Response(
        response: 200,
        description: 'Every table rolled on, root first, with the dice, total and selected entry.',
        content: new OA\JsonContent(ref: new Model(type: OracleTableResultResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, or has no "tables" list (a JSON object is not one) or no string "table".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(
        response: 422,
        description: 'The tables are invalid, none has the key asked, or a roll matches no entry.',
        content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)),
    )]
    public function resolve(Request $request): JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error('Send the oracle tables as JSON.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $body = $request->toArray();
        } catch (JsonException) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        $tables = $body['tables'] ?? null;
        $table = $body['table'] ?? null;
        if (!\is_array($tables) || !array_is_list($tables) || !\is_string($table)) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        try {
            $view = $this->queryBus->ask(new ResolveOracleTable($tables, $table));
        } catch (InvalidOracleTable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(OracleTableResultResponse::fromView($view));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
