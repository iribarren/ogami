<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignIdGenerator;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\GetCampaign;
use App\Play\Application\TransferCampaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\Transfer\TransferFromOutdatedRelease;
use App\Play\Domain\Campaign\Transfer\TransferToAnotherGameSystem;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Continuing one of the player's campaigns in a new one (ADR 0046; security.yaml restricts the
 * route to ROLE_SOLO_PLAYER). The source campaign is only read. A source of another player is not
 * found, exactly like an unknown one. Every malformed field is a 422, never a silent default.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignTransferController
{
    use ReadsJsonBodies;

    private const string MALFORMED_TRANSFER = 'Send a JSON object with a string "name", a list of strings "characterIds" and, to play a Flow, a string "flowKey", such as {"name": "Making a Name", "flowKey": "quick", "characterIds": []}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private CampaignIdGenerator $campaignIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/transfer', name: 'api_campaigns_transfer', methods: ['POST'])]
    #[OA\Post(operationId: 'transferCampaign', summary: 'Continue one of my campaigns in a new one, with another Flow or free play')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: TransferCampaignRequest::class)))]
    #[OA\Response(
        response: 201,
        description: 'The new campaign, on the latest release, with the source\'s NPCs, Threads and Campaign facts and the chosen Characters, all with their ids; Trackers at their initial value, no sessions. The Location header is its URL. The source is unchanged.',
        headers: [new OA\Header(header: 'Location', description: 'The URL of the new campaign.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: new Model(type: CampaignResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The body is not valid JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The source does not play the latest release of its GameSystem (upgrade it first), or the generated id of the new campaign is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The body is not an object with a string "name", a list of strings "characterIds" and a string or null "flowKey"; the name is blank or too long; the latest release has no Flow with this key; or a Character id is blank, repeated or not one of the source\'s Characters.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function transfer(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the new campaign as JSON.', 'The body is not valid JSON.');
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $flowKey = $body['flowKey'] ?? null;
        $characterIds = $body['characterIds'] ?? null;
        if (!\is_string($name) || (null !== $flowKey && !\is_string($flowKey)) || !$this->isListOfStrings($characterIds) || $this->hasJsonObjectAt($request, 'characterIds')) {
            return $this->error(self::MALFORMED_TRANSFER, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $newCampaignId = $this->campaignIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new TransferCampaign($campaignId, $user->id(), $newCampaignId, $name, $flowKey, $characterIds));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (TransferFromOutdatedRelease|TransferToAnotherGameSystem|CampaignAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidCampaignName|UnknownFlow|InvalidCharacter|UnknownCharacter|CharacterAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        $response = new JsonResponse(CampaignResponse::fromView($this->queryBus->ask(new GetCampaign($newCampaignId, $user->id()))), Response::HTTP_CREATED);
        $response->headers->set('Location', '/api/campaigns/'.$newCampaignId);

        return $response;
    }
}
