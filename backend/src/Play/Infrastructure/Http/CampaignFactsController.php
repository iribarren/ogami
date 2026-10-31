<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\AddFact;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\EditFact;
use App\Play\Application\FactIdGenerator;
use App\Play\Application\FactView;
use App\Play\Application\FillFactSlot;
use App\Play\Application\GetCampaign;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Facts\UnknownFactSlot;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownThread;
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
use Symfony\Component\Uid\Uuid;

/**
 * The Campaign facts of a solo player's campaigns: add a free fact, edit a fact, and fill a Fact
 * slot (security.yaml restricts these routes to ROLE_SOLO_PLAYER). A campaign of another player is not found, exactly
 * like an unknown one (the campaign is checked first), and so is a fact id the campaign does not
 * hold, such as one that is not a UUID; so is a link or subject naming an NPC, Thread, Place or fact it does not
 * hold. A slot key the pinned release does not declare is a value to fix (422), not a missing resource.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class CampaignFactsController
{
    use ReadsJsonBodies;

    private const string MALFORMED_ADD = 'Send a JSON object with string "label" and "text" and, optionally, a list or null "links" of {"kind": "npc" | "thread" | "place" | "fact", "id": "<uuid>"}, such as {"label": "The guild", "text": "Owes a debt.", "links": [{"kind": "npc", "id": "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"}]}.';
    private const string MALFORMED_EDIT = 'Send a JSON object with a list "links" of {"kind": "npc" | "thread" | "place" | "fact", "id": "<uuid>"} and string or null "label" and "text", such as {"label": "The guild", "text": "Owes a debt.", "links": []}.';
    private const string MALFORMED_FILL = 'Send a JSON object with exactly one of a string "text", a "subject" (the UUID of an NPC, Thread or Place) and a string "name" (a new NPC, Thread or Place), such as {"text": "Ashford"}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private FactIdGenerator $factIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/facts', name: 'api_campaigns_facts_add', methods: ['POST'])]
    #[OA\Post(operationId: 'addFact', summary: 'Add a free fact to one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: FactRequest::class)))]
    #[OA\Response(response: 201, description: 'The new free fact.', content: new OA\JsonContent(ref: new Model(type: FactResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no string "label" or "text", or a field or link of another JSON type or shape than documented (an unknown link kind, an id that is not a UUID).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or a link names an NPC, Thread, Place or fact it does not hold.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 500 facts, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long label or text, more than 20 links, a link repeated).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function add(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the fact as JSON.', self::MALFORMED_ADD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $label = $body['label'] ?? null;
        $text = $body['text'] ?? null;
        $links = $this->hasJsonObjectAt($request, 'links') ? null : $this->links($body['links'] ?? []);
        if (!\is_string($label) || !\is_string($text) || null === $links) {
            return $this->error(self::MALFORMED_ADD, Response::HTTP_BAD_REQUEST);
        }

        $factId = $this->factIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new AddFact($campaignId, $user->id(), $factId, $label, $text, $links));
        } catch (CampaignNotFound|UnknownNpc|UnknownThread|UnknownPlace|UnknownFact $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|FactAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidFact $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->fact($campaignId, static fn (FactView $fact): bool => $fact->id === $factId, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/campaigns/{campaignId}/facts/{factId}', name: 'api_campaigns_facts_edit', methods: ['PUT'])]
    #[OA\Put(operationId: 'editFact', summary: 'Edit a fact of one of my campaigns: a free fact\'s label and text, and any fact\'s links')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: EditFactRequest::class)))]
    #[OA\Response(response: 200, description: 'The edited fact.', content: new OA\JsonContent(ref: new Model(type: FactResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no list "links", or a field or link of another JSON type or shape than documented (an unknown link kind, an id that is not a UUID).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, it holds no fact with this id, or a link names an NPC, Thread, Place or fact it does not hold.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request changed the campaign meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'A value breaks a rule (blank or too long label or text, more than 20 links, a link repeated, a link to the fact itself), a free fact lacks its label or text, or a slot fact is given a label or text.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function edit(string $campaignId, string $factId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the fact as JSON.', self::MALFORMED_EDIT);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $label = $body['label'] ?? null;
        $text = $body['text'] ?? null;
        $links = $this->hasJsonObjectAt($request, 'links') ? null : $this->links($body['links'] ?? null);
        if ((null !== $label && !\is_string($label)) || (null !== $text && !\is_string($text)) || null === $links) {
            return $this->error(self::MALFORMED_EDIT, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new EditFact($campaignId, $user->id(), $factId, $label, $text, $links));
        } catch (CampaignNotFound|UnknownNpc|UnknownThread|UnknownPlace|UnknownFact $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignModifiedConcurrently $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidFact $exception) {
            // A blank id is not a value to fix: no fact has it.
            return '' === trim($factId) ? $this->error(\sprintf('Fact "%s" not found.', $factId), Response::HTTP_NOT_FOUND) : $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->fact($campaignId, static fn (FactView $fact): bool => $fact->id === $factId, $user, Response::HTTP_OK);
    }

    #[Route('/api/campaigns/{campaignId}/fact-slots/{slot}', name: 'api_campaigns_fact_slots_fill', methods: ['PUT'])]
    #[OA\Put(operationId: 'fillFactSlot', summary: 'Fill a Fact slot of one of my campaigns, or fill it again: its value is replaced, its links stay')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: FillFactSlotRequest::class)))]
    #[OA\Response(response: 200, description: 'The slot fact, newly created or refilled.', content: new OA\JsonContent(ref: new Model(type: FactResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, or a field is of another JSON type than documented (a subject that is not a UUID).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or the subject is an NPC, Thread or Place it does not hold.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign already holds 500 facts, NPCs, Threads or Places and the fill would add one, or another request changed it meanwhile.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The release declares no slot with this key, not exactly one of text, subject and name is sent, the value does not fit the slot\'s type (a text on an NPC, Thread or Place slot, a subject or name on a text slot), or it breaks a rule (blank or too long text or name).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function fill(string $campaignId, string $slot, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the fact slot value as JSON.', self::MALFORMED_FILL);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $body['text'] ?? null;
        $subject = $body['subject'] ?? null;
        $name = $body['name'] ?? null;
        if (!$this->isJsonObject($request) || (null !== $text && !\is_string($text)) || (null !== $subject && (!\is_string($subject) || !Uuid::isValid($subject))) || (null !== $name && !\is_string($name))) {
            return $this->error(self::MALFORMED_FILL, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new FillFactSlot($campaignId, $user->id(), $slot, $text, $subject, $name));
        } catch (CampaignNotFound|UnknownNpc|UnknownThread|UnknownPlace $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (CampaignLimitReached|CampaignModifiedConcurrently|FactAlreadyExists|NpcAlreadyExists|ThreadAlreadyExists|PlaceAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (UnknownFactSlot|InvalidFact|InvalidNpc|InvalidThread|InvalidPlace $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        return $this->fact($campaignId, static fn (FactView $fact): bool => $fact->slot === $slot, $user, Response::HTTP_OK);
    }

    /**
     * Reads a list of {kind, id} links: a kind of FactLinkKind and an id that is a UUID. Anything
     * else is a 400, never a silent default.
     *
     * @return list<array{kind: string, id: string}>|null the links, or null when the value is not such a list
     */
    private function links(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        $links = [];
        foreach ($value as $link) {
            $kind = \is_array($link) ? ($link['kind'] ?? null) : null;
            $id = \is_array($link) ? ($link['id'] ?? null) : null;
            if (!\is_string($kind) || null === FactLinkKind::tryFrom($kind) || !\is_string($id) || !Uuid::isValid($id)) {
                return null;
            }

            $links[] = ['kind' => $kind, 'id' => $id];
        }

        return $links;
    }

    /**
     * Reads the saved fact back through the campaign, answering as the command did when the campaign
     * or its release cannot be read any more.
     *
     * @param \Closure(FactView): bool $isTheFact
     */
    private function fact(string $campaignId, \Closure $isTheFact, AuthenticatedUser $user, int $status): JsonResponse
    {
        try {
            $facts = $this->queryBus->ask(new GetCampaign($campaignId, $user->id()))->facts;
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $this->unreadableRelease();
        }

        $fact = array_find($facts, $isTheFact) ?? throw new \LogicException('The fact was just saved.');

        return new JsonResponse(FactResponse::fromView($fact), $status);
    }
}
