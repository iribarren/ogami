<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\AskLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
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
 * Asks a likelihood oracle sent with the request a yes/no question, for any signed-in user.
 * Answers are not stored.
 */
#[AsController]
#[OA\Tag(name: 'Oracles')]
final readonly class LikelihoodOracleController
{
    private const string MALFORMED_BODY = 'Send a JSON object with an "oracle" object, a string "likelihood" and an optional integer "chaosFactor", such as {"oracle": {"sides": 100, …}, "likelihood": "likely", "chaosFactor": 5}.';

    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/likelihood-answers', name: 'api_likelihood_answers_create', methods: ['POST'])]
    #[OA\Post(operationId: 'askLikelihoodOracle', summary: 'Ask a likelihood oracle a yes/no question')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: LikelihoodAnswerRequest::class)))]
    #[OA\Response(
        response: 200,
        description: 'The answer, the roll and the effective target it was compared with.',
        content: new OA\JsonContent(ref: new Model(type: LikelihoodAnswerResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no "oracle" object (a non-empty JSON list is not one) or no string "likelihood", or a non-integer "chaosFactor".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(
        response: 422,
        description: 'The oracle is invalid, it has no such likelihood level, or the chaos factor is out of range or given without chaos.',
        content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)),
    )]
    public function ask(Request $request): JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error('Send the question as JSON.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $body = $request->toArray();
        } catch (JsonException) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        $oracle = $body['oracle'] ?? null;
        $likelihood = $body['likelihood'] ?? null;
        $chaosFactor = $body['chaosFactor'] ?? null;
        if (!$this->isJsonObject($oracle) || !\is_string($likelihood) || (null !== $chaosFactor && !\is_int($chaosFactor))) {
            return $this->error(self::MALFORMED_BODY, Response::HTTP_BAD_REQUEST);
        }

        try {
            $view = $this->queryBus->ask(new AskLikelihoodOracle($oracle, $likelihood, $chaosFactor));
        } catch (InvalidLikelihoodOracle $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(LikelihoodAnswerResponse::fromView($view));
    }

    /**
     * A decoded JSON object is a non-list array. An empty object decodes to an empty array,
     * indistinguishable from an empty list, so both pass here and the domain rejects them.
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private function isJsonObject(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
