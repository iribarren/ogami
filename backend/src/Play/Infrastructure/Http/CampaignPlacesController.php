<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\AddPlace;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\ClosePlace;
use App\Play\Application\EditPlace;
use App\Play\Application\GetCampaign;
use App\Play\Application\PlaceIdGenerator;
use App\Play\Application\PlaceView;
use App\Play\Application\ReopenPlace;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
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
 * The Places of a solo player's campaigns: add, edit, close and reopen (security.yaml restricts these
 * routes to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly like an unknown
 * one (the campaign is checked first), and so is a Place id the campaign does not hold, such as one
 * that is not a UUID. No release Tag applies to Places yet, so a Tag is always refused.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignPlacesController
{
    use ReadsJsonBodies;

    private const string MALFORMED_THREAD = 'Send a JSON object with a string "name" and, optionally, a string or null "notes", lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Saltmarsh", "notes": "A fishing town", "weight": 2}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private PlaceIdGenerator $placeIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/places', name: 'api_campaigns_places_add', methods: ['POST'])]
    #[OA\Post(operationId: 'addPlace', summary: 'Add a Place to one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PlaceRequest::class)))]
    #[OA\Response(response: 201, description: 'The new, open Place.', content: new OA\JsonContent(ref: new Model(type: PlaceResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 500 Places, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too long notes, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is given: it is not in the pinned release, or no Tag applies to Places yet.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function add(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        $placeId = $this->placeIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new AddPlace($campaignId, $user->id(), $placeId, ...$fields));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|PlaceAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidPlace|UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->place($campaignId, $placeId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/places/{placeId}', name: 'api_campaigns_places_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editPlace', summary: 'Replace the name, notes, Tags, free tags and weight of a Place of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PlaceRequest::class)))]
    #[OA\Response(response: 200, description: 'The edited Place; its status stays.', content: new OA\JsonContent(ref: new Model(type: PlaceResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Place with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too long notes, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is given: it is not in the pinned release, or no Tag applies to Places yet.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function edit(string $campaignId, string $placeId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $this->commandBus->dispatch(new EditPlace($campaignId, $user->id(), $placeId, ...$fields));
        } catch (CampaignNotFound|UnknownPlace $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidPlace $exception) {
            // A blank id is not a value to fix: no Place has it.
            return '' === trim($placeId) ? $this->blankPlaceNotFound($placeId) : $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->place($campaignId, $placeId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/places/{placeId}/close', name: 'api_campaigns_places_close', methods: ['POST'])]
    #[OA\Post(operationId: 'closePlace', summary: 'Close a Place of one of my campaigns: flows no longer pick or count it')]
    #[OA\Response(response: 200, description: 'The closed Place. Closing a closed one changes nothing.', content: new OA\JsonContent(ref: new Model(type: PlaceResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Place with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function close(string $campaignId, string $placeId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ClosePlace($campaignId, $user->id(), $placeId));
        } catch (CampaignNotFound|UnknownPlace $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidPlace) {
            return $this->blankPlaceNotFound($placeId);
        }

        return $this->place($campaignId, $placeId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/places/{placeId}/reopen', name: 'api_campaigns_places_reopen', methods: ['POST'])]
    #[OA\Post(operationId: 'reopenPlace', summary: 'Reopen a Place of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The reopened Place. Reopening an open one changes nothing.', content: new OA\JsonContent(ref: new Model(type: PlaceResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Place with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function reopen(string $campaignId, string $placeId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ReopenPlace($campaignId, $user->id(), $placeId));
        } catch (CampaignNotFound|UnknownPlace $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidPlace) {
            return $this->blankPlaceNotFound($placeId);
        }

        return $this->place($campaignId, $placeId, $user, Response::HTTP_OK);
    }

    /**
     * Reads the Place fields of the body: a string name, a string or null notes, lists of
     * strings or null tags and free tags and an integer or null weight. A field of another JSON
     * type is a 400, never a silent default.
     *
     * @return array{name: string, notes: ?string, tags: list<string>, freeTags: list<string>, weight: int}|JsonResponse the fields as the commands take them (omitted ones defaulted), or the error response to send
     */
    private function fields(Request $request): array|JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the Place as JSON.', self::MALFORMED_THREAD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $tags = $body['tags'] ?? [];
        $freeTags = $body['freeTags'] ?? [];
        $notes = $body['notes'] ?? null;
        $weight = $body['weight'] ?? 1;
        if (!\is_string($name) || null !== $notes && !\is_string($notes) || !$this->isListOfStrings($tags) || !$this->isListOfStrings($freeTags) || !\is_int($weight)) {
            return $this->error(self::MALFORMED_THREAD, Response::HTTP_BAD_REQUEST);
        }

        return ['name' => $name, 'notes' => $notes, 'tags' => $tags, 'freeTags' => $freeTags, 'weight' => $weight];
    }

    /**
     * A blank id (all spaces in the path) cannot be a Place id, so no campaign holds that Place.
     */
    private function blankPlaceNotFound(string $placeId): JsonResponse
    {
        return $this->error(\sprintf('Place "%s" not found.', $placeId), Response::HTTP_NOT_FOUND);
    }

    /**
     * The command has already saved when the answer is built, so a release that can no longer be read
     * is the same server error the commands answer with, never an unmapped exception.
     */
    private function place(string $campaignId, string $placeId, AuthenticatedUser $user, int $status): JsonResponse
    {
        try {
            $places = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->places;
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        $place = array_find($places, static fn (PlaceView $place): bool => $place->id === $placeId) ?? throw new \LogicException('The Place was just saved.');

        return new JsonResponse(PlaceResponse::fromView($place), $status);
    }
}
