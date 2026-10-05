<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Health\CheckHealth;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[OA\Tag(name: 'Health')]
final readonly class HealthController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(operationId: 'getHealth', summary: 'Report whether the API and its database are up')]
    #[OA\Response(
        response: 200,
        description: 'The API is up; the database status is reported separately.',
        content: new OA\JsonContent(ref: new Model(type: HealthResponse::class)),
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(HealthResponse::fromReport($this->queryBus->ask(new CheckHealth())));
    }
}
