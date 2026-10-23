<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\AddNpc;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CharacterIdGenerator;
use App\Play\Application\CharacterView;
use App\Play\Application\CloseNpc;
use App\Play\Application\EditNpc;
use App\Play\Application\GetCampaign;
use App\Play\Application\NpcIdGenerator;
use App\Play\Application\NpcView;
use App\Play\Application\PromoteNpc;
use App\Play\Application\ReopenNpc;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcAlreadyPromoted;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
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
 * The NPCs of a solo player's campaigns: add, edit, close, reopen and promote (security.yaml restricts these
 * routes to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly like an unknown
 * one (the campaign is checked first), and so is an NPC id the campaign does not hold, such as one
 * that is not a UUID. The Tags come from the campaign's pinned release.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignNpcsController
{
    use ReadsJsonBodies;

    private const string MALFORMED_NPC = 'Send a JSON object with a string "name" and, optionally, a string or null "agenda", lists of strings or null "tags" and "freeTags", and an integer or null "weight", such as {"name": "Mira", "tags": ["ally"], "weight": 2}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private NpcIdGenerator $npcIds,
        private CharacterIdGenerator $characterIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/npcs', name: 'api_campaigns_npcs_add', methods: ['POST'])]
    #[OA\Post(operationId: 'addNpc', summary: 'Add an NPC to one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: NpcRequest::class)))]
    #[OA\Response(response: 201, description: 'The new, open NPC.', content: new OA\JsonContent(ref: new Model(type: NpcResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 500 NPCs, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too long agenda, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function add(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        $npcId = $this->npcIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new AddNpc($campaignId, $user->id(), $npcId, ...$fields));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|NpcAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidNpc|UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->npc($campaignId, $npcId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/npcs/{npcId}', name: 'api_campaigns_npcs_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editNpc', summary: 'Replace the name, Agenda, Tags, free tags and weight of an NPC of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: NpcRequest::class)))]
    #[OA\Response(response: 200, description: 'The edited NPC; its status stays.', content: new OA\JsonContent(ref: new Model(type: NpcResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "name", or a field of another JSON type than documented.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no NPC with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long name, too long agenda, too many or repeated Tags or free tags, weight out of 1 to 3) or a Tag is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function edit(string $campaignId, string $npcId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $fields = $this->fields($request);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $this->commandBus->dispatch(new EditNpc($campaignId, $user->id(), $npcId, ...$fields));
        } catch (CampaignNotFound|UnknownNpc $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidNpc $exception) {
            // A blank id is not a value to fix: no NPC has it.
            return '' === trim($npcId) ? $this->blankNpcNotFound($npcId) : $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->npc($campaignId, $npcId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/npcs/{npcId}/close', name: 'api_campaigns_npcs_close', methods: ['POST'])]
    #[OA\Post(operationId: 'closeNpc', summary: 'Close an NPC of one of my campaigns: flows no longer pick or count it')]
    #[OA\Response(response: 200, description: 'The closed NPC. Closing a closed one, or a promoted one, changes nothing.', content: new OA\JsonContent(ref: new Model(type: NpcResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no NPC with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function close(string $campaignId, string $npcId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new CloseNpc($campaignId, $user->id(), $npcId));
        } catch (CampaignNotFound|UnknownNpc $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidNpc) {
            return $this->blankNpcNotFound($npcId);
        }

        return $this->npc($campaignId, $npcId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/npcs/{npcId}/reopen', name: 'api_campaigns_npcs_reopen', methods: ['POST'])]
    #[OA\Post(operationId: 'reopenNpc', summary: 'Reopen an NPC of one of my campaigns')]
    #[OA\Response(response: 200, description: 'The reopened NPC. Reopening an open one changes nothing.', content: new OA\JsonContent(ref: new Model(type: NpcResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no NPC with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The NPC was promoted to a Character and stays closed, or another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function reopen(string $campaignId, string $npcId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ReopenNpc($campaignId, $user->id(), $npcId));
        } catch (CampaignNotFound|UnknownNpc $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently|NpcAlreadyPromoted $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidNpc) {
            return $this->blankNpcNotFound($npcId);
        }

        return $this->npc($campaignId, $npcId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/npcs/{npcId}/promote', name: 'api_campaigns_npcs_promote', methods: ['POST'])]
    #[OA\Post(operationId: 'promoteNpc', summary: 'Promote an NPC of one of my campaigns to a Character; the NPC is closed for good')]
    #[OA\Response(response: 201, description: 'The new Character: the NPC\'s name, Tags and free tags, the default of every sheet field, and the NPC as its origin. Any request body is ignored.', content: new OA\JsonContent(ref: new Model(type: CharacterResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or it holds no NPC with this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The NPC was promoted already, the campaign already holds 100 Characters, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A Tag of the NPC is not in the pinned release.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function promote(string $campaignId, string $npcId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $characterId = $this->characterIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new PromoteNpc($campaignId, $user->id(), $npcId, $characterId));
        } catch (CampaignNotFound|UnknownNpc $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NpcAlreadyPromoted|CampaignLimitReached|CharacterAlreadyExists|CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidNpc) {
            return $this->blankNpcNotFound($npcId);
        } catch (UnknownReleaseTag|TagNotApplicable $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        $characters = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->characters;
        $character = array_find($characters, static fn (CharacterView $character): bool => $character->id === $characterId) ?? throw new \LogicException('The Character was just saved.');

        return new JsonResponse(CharacterResponse::fromView($character), Response::HTTP_CREATED);
    }

    /**
     * Reads the NPC fields of the body: a string name, and a string or null agenda, lists of
     * strings or null tags and free tags and an integer or null weight. A field of another JSON
     * type is a 400, never a silent default.
     *
     * @return array{name: string, agenda: ?string, tags: list<string>, freeTags: list<string>, weight: int}|JsonResponse the fields as the commands take them (omitted ones defaulted), or the error response to send
     */
    private function fields(Request $request): array|JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the NPC as JSON.', self::MALFORMED_NPC);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $name = $body['name'] ?? null;
        $agenda = $body['agenda'] ?? null;
        $tags = $body['tags'] ?? [];
        $freeTags = $body['freeTags'] ?? [];
        $weight = $body['weight'] ?? 1;
        if (!\is_string($name) || (null !== $agenda && !\is_string($agenda)) || !$this->isListOfStrings($tags) || !$this->isListOfStrings($freeTags) || !\is_int($weight)) {
            return $this->error(self::MALFORMED_NPC, Response::HTTP_BAD_REQUEST);
        }

        return ['name' => $name, 'agenda' => $agenda, 'tags' => $tags, 'freeTags' => $freeTags, 'weight' => $weight];
    }

    /**
     * A blank id (all spaces in the path) cannot be an NPC id, so no campaign holds that NPC.
     */
    private function blankNpcNotFound(string $npcId): JsonResponse
    {
        return $this->error(\sprintf('NPC "%s" not found.', $npcId), Response::HTTP_NOT_FOUND);
    }

    /**
     * @throws CampaignNotFound
     */
    private function npc(string $campaignId, string $npcId, AuthenticatedUser $user, int $status): JsonResponse
    {
        $npcs = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->npcs;
        $npc = array_find($npcs, static fn (NpcView $npc): bool => $npc->id === $npcId) ?? throw new \LogicException('The NPC was just saved.');

        return new JsonResponse(NpcResponse::fromView($npc), $status);
    }
}
