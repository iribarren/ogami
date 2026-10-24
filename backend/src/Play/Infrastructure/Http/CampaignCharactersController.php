<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\AddCharacter;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CharacterIdGenerator;
use App\Play\Application\CharacterView;
use App\Play\Application\EditCharacter;
use App\Play\Application\GetCampaign;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
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
 * The Characters of a solo player's campaigns: add and edit (security.yaml restricts these routes
 * to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly like an unknown one (the
 * campaign is checked first), and so is a Character id the campaign does not hold. The Tags and the
 * sheet come from the campaign's pinned release; the values are checked against that sheet.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignCharactersController
{
    use ReadsJsonBodies;

    private const string MALFORMED_CHARACTER = 'Send a JSON object with a string "name" and, optionally, lists of strings or null "tags" and "freeTags" and an object or null "values", such as {"name": "Vi", "tags": ["ally"], "values": {"reflexes": 7}}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private CharacterIdGenerator $characterIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/characters', name: 'api_campaigns_characters_add', methods: ['POST'])]
    #[OA\Post(operationId: 'addCharacter', summary: 'Add a Character to one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CharacterRequest::class)))]
    #[OA\Response(response: 201, description: 'The new Character, its values complete: a field not given has its default.', content: new OA\JsonContent(ref: new Model(type: CharacterResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 100 Characters, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too many or repeated Tags or free tags, "values" not an object, an unknown sheet field, a value of the wrong type or out of its bounds), the "method" is unknown or a Tag is not in the pinned release, or a rule of structured character creation is broken (the body then has a code): the method is missing or not available on the sheet, a value is sent for a field the server sets, a point budget value is out of bounds or the budget is exceeded.', content: new OA\JsonContent(ref: new Model(type: CharacterCreationErrorResponse::class)))]
    public function add(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        // The method belongs to creation: an edit ignores it.
        $method = $request->toArray()['method'] ?? null;
        if (null !== $method && !\is_string($method)) {
            return $this->error(self::MALFORMED_CHARACTER, Response::HTTP_BAD_REQUEST);
        }

        $characterId = $this->characterIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new AddCharacter($campaignId, $user->id(), $characterId, ...$fields, method: $method));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|CharacterAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidCharacter $exception) {
            $body = $exception->refusal instanceof \App\Play\Domain\Campaign\Characters\CreationRefusal
                ? CharacterCreationErrorResponse::with(CharacterCreationErrorCode::of($exception->refusal), $exception->getMessage())
                : CharacterCreationErrorResponse::withoutCode($exception->getMessage());

            return new JsonResponse($body->toArray(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->character($campaignId, $characterId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/characters/{characterId}', name: 'api_campaigns_characters_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editCharacter', summary: 'Replace the name, Tags, free tags and values of a Character of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CharacterRequest::class)))]
    #[OA\Response(response: 200, description: 'The edited Character; a value not given went back to its default, the NPC it was promoted from stays.', content: new OA\JsonContent(ref: new Model(type: CharacterResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no Character with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too many or repeated Tags or free tags, "values" not an object, an unknown sheet field, a value of the wrong type or out of its bounds) or a Tag is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function edit(string $campaignId, string $characterId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $this->commandBus->dispatch(new EditCharacter($campaignId, $user->id(), $characterId, ...$fields));
        } catch (CampaignNotFound|UnknownCharacter $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidCharacter $exception) {
            // A blank id is not a value to fix: no Character has it.
            return '' === trim($characterId) ? $this->error(\sprintf('Character "%s" not found.', $characterId), Response::HTTP_NOT_FOUND) : $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->character($campaignId, $characterId, $user, Response::HTTP_OK);
    }

    /**
     * Reads the Character fields of the body: a string name, lists of strings or null tags and free
     * tags and an object or null values. A field of another JSON type is a 400, never a silent
     * default; values that are not an object are a 422, as a value that breaks the sheet is.
     *
     * @return array{name: string, tags: list<string>, freeTags: list<string>, values: array<mixed>}|JsonResponse the fields as the commands take them (omitted ones defaulted), or the error response to send
     */
    private function fields(Request $request): array|JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the Character as JSON.', self::MALFORMED_CHARACTER);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $tags = $body['tags'] ?? [];
        $freeTags = $body['freeTags'] ?? [];
        if (!\is_string($name) || !$this->isListOfStrings($tags) || !$this->isListOfStrings($freeTags)) {
            return $this->error(self::MALFORMED_CHARACTER, Response::HTTP_BAD_REQUEST);
        }

        $values = $body['values'] ?? [];
        if (!\is_array($values) || ([] !== $values && !$this->hasJsonObjectAt($request, 'values'))) {
            return $this->error('The "values" must be an object of sheet field keys to values.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['name' => $name, 'tags' => $tags, 'freeTags' => $freeTags, 'values' => $values];
    }

    /**
     * @throws CampaignNotFound
     */
    private function character(string $campaignId, string $characterId, AuthenticatedUser $user, int $status): JsonResponse
    {
        $characters = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->characters;
        $character = array_find($characters, static fn (CharacterView $character): bool => $character->id === $characterId) ?? throw new \LogicException('The Character was just saved.');

        return new JsonResponse(CharacterResponse::fromView($character), $status);
    }
}
