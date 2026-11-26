<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\ListPublishedGameSystems;
use App\Studio\Application\PublishedGameSystemSummary;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The published GameSystems a draft can be copied from (security.yaml restricts this route to
 * ROLE_GAME_MANAGER). Releases are public to Play already, so every one is listed whoever owns it,
 * file-managed presets included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class PublishedGameSystemController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    #[Route('/api/studio/published-game-systems', name: 'api_studio_published_game_systems_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listPublishedGameSystemsForCopy', summary: 'List the published GameSystems a draft can be copied from')]
    #[OA\Response(
        response: 200,
        description: 'The latest release of every published GameSystem, ordered by name ignoring case, then key; empty when nothing was published.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: PublishedGameSystemForCopyResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(): JsonResponse
    {
        $summaries = $this->queryBus->ask(new ListPublishedGameSystems());

        return new JsonResponse(array_map(
            PublishedGameSystemForCopyResponse::fromSummary(...),
            // A summary without a release is one whose stored content no longer parses: it cannot be copied.
            array_values(array_filter($summaries, static fn (PublishedGameSystemSummary $summary): bool => $summary->release instanceof \App\Studio\Application\PublishedReleaseView)),
        ));
    }
}
