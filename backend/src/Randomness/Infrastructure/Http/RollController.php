<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\RollDice;
use App\Randomness\Domain\InvalidDiceExpression;
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
 * Rolls dice notation for any signed-in user. Rolls are not stored.
 */
#[AsController]
#[OA\Tag(name: 'Rolls')]
final readonly class RollController
{
    private const string MALFORMED_BODY = 'Send a JSON object with a string "expression", such as {"expression": "2d6+1"}.';

    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/rolls', name: 'api_rolls_create', methods: ['POST'])]
    #[OA\Post(operationId: 'rollDice', summary: 'Roll a dice expression')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RollRequest::class)))]
    #[OA\Response(
        response: 200,
        description: 'The roll: its total and every die rolled, dropped ones included.',
        content: new OA\JsonContent(ref: new Model(type: RollResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "expression".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(
        response: 422,
        description: 'The expression is not valid dice notation, exceeds a limit or divides by zero.',
        content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)),
    )]
    public function roll(Request $request): JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error('Send the dice expression as JSON.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $expression = $request->toArray()['expression'] ?? null;
        } catch (JsonException) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($expression)) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        try {
            $view = $this->queryBus->ask(new RollDice($expression));
        } catch (InvalidDiceExpression $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(RollResponse::fromView($view));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
