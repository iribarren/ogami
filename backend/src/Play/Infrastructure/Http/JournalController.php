<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\GetJournal;
use App\Play\Application\GetJournalEntry;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\JournalEntryNotFound;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordRoll;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Shared\Application\Bus\Command;
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
 * The journal of a solo player's campaign (security.yaml restricts these routes to
 * ROLE_SOLO_PLAYER). Every entry is recorded in the campaign's current scene; rolls and oracle
 * answers are rolled on the server against the campaign's pinned release, and a failed one
 * records nothing. A campaign of another player is not found, exactly like an unknown one.
 */
#[AsController]
#[OA\Tag(name: 'Play')]
final readonly class JournalController
{
    use ReadsJsonBodies;

    private const string MALFORMED_NOTE = 'Send a JSON object with a string "text", such as {"text": "The gate is open."}.';
    private const string MALFORMED_ROLL = 'Send a JSON object with a string "expression", such as {"expression": "2d6+1"}.';
    private const string MALFORMED_LIKELIHOOD = 'Send a JSON object with a string "likelihood", and optionally an integer "chaosFactor" and a string "question", such as {"likelihood": "likely", "chaosFactor": 5, "question": "Is the door locked?"}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private JournalEntryIdGenerator $entryIds,
    ) {
    }

    #[Route('/api/campaigns/{campaignId}/journal', name: 'api_campaigns_journal_get', methods: ['GET'])]
    #[OA\Get(operationId: 'getJournal', summary: 'Read the journal of one of my campaigns')]
    #[OA\Response(
        response: 200,
        description: 'Every entry, in recording order (then id order).',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: JournalEntryResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function journal(string $campaignId, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            $entries = $this->queryBus->ask(new GetJournal($campaignId, $user->id()));
        } catch (CampaignNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(array_map(JournalEntryResponse::fromView(...), $entries));
    }

    #[Route('/api/campaigns/{campaignId}/journal/notes', name: 'api_campaigns_journal_notes_record', methods: ['POST'])]
    #[OA\Post(operationId: 'recordNote', summary: 'Write a note in the current scene of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecordNoteRequest::class)))]
    #[OA\Response(response: 201, description: 'The new entry.', content: new OA\JsonContent(ref: new Model(type: JournalEntryResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "text".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no current scene, or the generated entry id is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The text is blank or too long.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function recordNote(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the note as JSON.', self::MALFORMED_NOTE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $body['text'] ?? null;
        if (!\is_string($text)) {
            return $this->error(self::MALFORMED_NOTE, Response::HTTP_BAD_REQUEST);
        }

        $entryId = $this->entryIds->generate()->toString();

        return $this->record(new RecordNote($entryId, $campaignId, $user->id(), $text), $entryId, $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/journal/rolls', name: 'api_campaigns_journal_rolls_record', methods: ['POST'])]
    #[OA\Post(operationId: 'recordRoll', summary: 'Roll dice on the server and record the roll in the current scene of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecordRollRequest::class)))]
    #[OA\Response(response: 201, description: 'The new entry.', content: new OA\JsonContent(ref: new Model(type: JournalEntryResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no string "expression".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no current scene, or the generated entry id is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The dice expression is invalid.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function recordRoll(string $campaignId, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the roll as JSON.', self::MALFORMED_ROLL);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expression = $body['expression'] ?? null;
        if (!\is_string($expression)) {
            return $this->error(self::MALFORMED_ROLL, Response::HTTP_BAD_REQUEST);
        }

        $entryId = $this->entryIds->generate()->toString();

        return $this->record(new RecordRoll($entryId, $campaignId, $user->id(), $expression), $entryId, $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/journal/oracle-tables/{oracleKey}', name: 'api_campaigns_journal_oracle_tables_record', methods: ['POST'])]
    #[OA\Post(operationId: 'recordOracleTableResult', summary: 'Roll on an oracle table of the pinned release and record the result in the current scene of one of my campaigns')]
    #[OA\Response(response: 201, description: 'The new entry.', content: new OA\JsonContent(ref: new Model(type: JournalEntryResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no oracle table with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no current scene, or the generated entry id is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function recordOracleTableResult(string $campaignId, string $oracleKey, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $entryId = $this->entryIds->generate()->toString();

        return $this->record(new RecordOracleTableResult($entryId, $campaignId, $user->id(), $oracleKey), $entryId, $campaignId, $user);
    }

    #[Route('/api/campaigns/{campaignId}/journal/likelihood-oracles/{oracleKey}', name: 'api_campaigns_journal_likelihood_oracles_record', methods: ['POST'])]
    #[OA\Post(operationId: 'recordLikelihoodAnswer', summary: 'Ask a likelihood oracle of the pinned release and record the answer in the current scene of one of my campaigns')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecordLikelihoodAnswerRequest::class)))]
    #[OA\Response(response: 201, description: 'The new entry.', content: new OA\JsonContent(ref: new Model(type: JournalEntryResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed: no string "likelihood", a non-integer "chaosFactor" or a non-string "question".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a solo player.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No campaign of the player has this id, or its pinned release has no likelihood oracle with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The campaign has no current scene, or the generated entry id is already taken.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The likelihood level is unknown, the chaos factor is out of range or not expected, or the question is too long.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function recordLikelihoodAnswer(string $campaignId, string $oracleKey, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the question as JSON.', self::MALFORMED_LIKELIHOOD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $likelihood = $body['likelihood'] ?? null;
        $chaosFactor = $body['chaosFactor'] ?? null;
        $question = $body['question'] ?? null;
        if (!\is_string($likelihood) || (null !== $chaosFactor && !\is_int($chaosFactor)) || (null !== $question && !\is_string($question))) {
            return $this->error(self::MALFORMED_LIKELIHOOD, Response::HTTP_BAD_REQUEST);
        }

        $entryId = $this->entryIds->generate()->toString();

        return $this->record(
            new RecordLikelihoodAnswer($entryId, $campaignId, $user->id(), $oracleKey, $likelihood, $chaosFactor, $question),
            $entryId,
            $campaignId,
            $user,
        );
    }

    /**
     * Dispatches a Record* command, then answers with the recorded entry, or with the error.
     */
    private function record(Command $command, string $entryId, string $campaignId, AuthenticatedUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);

            return new JsonResponse(
                JournalEntryResponse::fromView($this->queryBus->ask(new GetJournalEntry($entryId, $campaignId, $user->id()))),
                Response::HTTP_CREATED,
            );
        } catch (CampaignNotFound|UnknownGameSystemOracle|JournalEntryNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (NoCurrentScene|JournalEntryAlreadyExists $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (InvalidJournalEntryContent|InvalidDiceExpression|InvalidLikelihoodOracle $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
