<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ListGameSystems;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The release catalog a solo player creates campaigns from (security.yaml restricts it to
 * ROLE_SOLO_PLAYER).
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class GameSystemController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/play/game-systems', name: 'api_play_game_systems_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listGameSystems', summary: 'List the GameSystems a campaign can be created with')]
    #[OA\Response(
        response: 200,
        description: 'The latest published release of each GameSystem, by name ignoring case, then key.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: GameSystemSummaryResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(
            GameSystemSummaryResponse::fromView(...),
            $this->queryBus->ask(new ListGameSystems()),
        ));
    }
}
