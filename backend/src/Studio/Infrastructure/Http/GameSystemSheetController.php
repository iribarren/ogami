<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use App\Studio\Application\DeclareSheetLayout;
use App\Studio\Application\DeleteSheetField;
use App\Studio\Application\GetGameSystemSheet;
use App\Studio\Application\MoveSheetField;
use App\Studio\Application\RemoveSheetLayout;
use App\Studio\Application\RemoveSheetPointBudget;
use App\Studio\Application\RemoveSheetPointBudgetEntry;
use App\Studio\Application\SaveSheetField;
use App\Studio\Application\SaveSheetLayout;
use App\Studio\Application\SaveSheetPointBudget;
use App\Studio\Application\SaveSheetPointBudgetEntry;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\SeveralPointBudgets;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\GameSystem\SheetFieldKeyTaken;
use App\Studio\Domain\GameSystem\SheetFieldNotFound;
use App\Studio\Domain\GameSystem\SheetPointBudgetNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use Doctrine\DBAL\Connection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The sheet of a game manager's GameSystem drafts: a read, one create, replace, delete and move
 * command per field, the layout's save, declare and remove, and the point budgets' put and delete by key and the single point budget's save and remove (security.yaml restricts these routes
 * to ROLE_GAME_MANAGER). Like the drafts,
 * they belong to their creator: another user's key is not found. Every write carries the draft
 * version the client read and answers with the sheet as it is now, new version included.
 */
#[AsController]
#[OA\Tag(name: 'Studio')]
final readonly class GameSystemSheetController
{
    use ReadsJsonBodies;

    private const string MALFORMED_SAVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and a "field" object, such as {"expectedVersion": 3, "field": {"key": "luck", "label": "Luck", "type": "number"}}.';
    private const string MALFORMED_MOVE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, and an integer "index", the field\'s new position, such as {"expectedVersion": 3, "index": 0}.';
    private const string MALFORMED_DELETE = 'Send the draft version you read as an integer "expectedVersion" query parameter, such as ?expectedVersion=3.';
    private const string MALFORMED_LAYOUT = 'Send a JSON object with an integer "expectedVersion", the draft version you read, an optional "columns" and a "groups" list of objects, such as {"expectedVersion": 3, "columns": 2, "groups": [{"name": "Stats", "columns": 2}]}.';
    private const string MALFORMED_POINT_BUDGET = 'Send a JSON object with an integer "expectedVersion", the draft version you read, an integer "total", a "fields" list of field keys and optionally a "label", "min" and "max", such as {"expectedVersion": 3, "total": 460, "fields": ["str", "con"], "min": 15, "max": 90}.';
    private const string MALFORMED_POINT_BUDGET_ENTRY = 'Send a JSON object with an integer "expectedVersion", the draft version you read, a "label", an integer "total", a "fields" list of field keys and optionally "min" and "max", such as {"expectedVersion": 3, "label": "Skill points", "total": 8, "fields": ["lore"], "max": 3}.';
    private const string MALFORMED_DECLARE = 'Send a JSON object with an integer "expectedVersion", the draft version you read, such as {"expectedVersion": 3}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private Connection $connection,
    ) {
    }

    #[Route('/api/studio/game-systems/{key}/sheet', name: 'api_studio_game_systems_sheet', methods: ['GET'])]
    #[OA\Get(operationId: 'getGameSystemSheet', summary: 'Read the sheet of one of my GameSystem drafts: layout, fields, the trackers a formula may read and the field keys of its latest release')]
    #[OA\Response(response: 200, description: 'The sheet as stored, in order, and the draft version. A schema version 1 draft has no fields.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key (another user\'s is not found either).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function sheet(string $key, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        try {
            return $this->sheetOf($user, $key, Response::HTTP_OK);
        } catch (GameSystemNotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/studio/game-systems/{key}/sheet/fields', name: 'api_studio_game_systems_sheet_field_create', methods: ['POST'])]
    #[OA\Post(operationId: 'createSheetField', summary: 'Add a field to the sheet of one of my GameSystem drafts')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveSheetFieldRequest::class)))]
    #[OA\Response(response: 201, description: 'The draft\'s sheet with the field appended and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "field" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the key names another field. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The field breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), or the draft has schema version 1 (no sheet fields); nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function create(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, null, $request, $user, Response::HTTP_CREATED);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/fields/{field}', name: 'api_studio_game_systems_sheet_field_replace', methods: ['PUT'])]
    #[OA\Put(operationId: 'replaceSheetField', summary: 'Replace a field of the sheet of one of my GameSystem drafts, renaming it when the body has another key')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveSheetFieldRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the field replaced in place and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion" or no "field" object.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the sheet has no field with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile (reload it and try again), or the new key names another field. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The field breaks the release contract\'s rules, or leaves another part of the draft breaking them (the message starts with the path), the draft has schema version 1, or a rename is read by the draft (the paths say where); nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function replace(string $key, string $field, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return $this->save($key, $field, $request, $user, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/fields/{field}', name: 'api_studio_game_systems_sheet_field_delete', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'deleteSheetField', summary: 'Delete a field of the sheet of one of my GameSystem drafts that nothing reads')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet without the field and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the sheet has no field with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft reads the field (the paths say where), or has schema version 1; nothing changed.', content: new OA\JsonContent(oneOf: [new OA\Schema(ref: new Model(type: ErrorResponse::class)), new OA\Schema(ref: new Model(type: OracleInUseResponse::class))]))]
    public function delete(string $key, string $field, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $this->expectedVersionParameter($request);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new DeleteSheetField($user->id(), $key, $expectedVersion, $field), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/fields/{field}/move', name: 'api_studio_game_systems_sheet_field_move', methods: ['POST'])]
    #[OA\Post(operationId: 'moveSheetField', summary: 'Move a field of the sheet of one of my GameSystem drafts to another position of the field list')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MoveSheetFieldRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the field at its new position and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, or has no integer "expectedVersion" or no integer "index".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or the sheet has no field with the key in the path.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: '"index" is outside the field list (the message starts with "index"), the draft breaks the release contract\'s rules elsewhere (the message starts with the path), or it has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function move(string $key, string $field, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the new position as JSON.', self::MALFORMED_MOVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $index = $body['index'] ?? null;
        if (!\is_int($expectedVersion) || !\is_int($index)) {
            return $this->error(self::MALFORMED_MOVE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new MoveSheetField($user->id(), $key, $expectedVersion, $field, $index), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/layout', name: 'api_studio_game_systems_sheet_layout_save', methods: ['PUT'])]
    #[OA\Put(operationId: 'saveSheetLayout', summary: 'Replace the layout of the sheet of one of my GameSystem drafts: its columns and its groups, renaming, reordering, adding and dropping groups')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveSheetLayoutRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the new layout (a renamed group\'s fields follow it) and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, has no integer "expectedVersion", or "groups" is not a list of objects.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The sheet declares no layout yet (the message starts with "sheet.groups"), "groups" is empty or names a missing group or one twice (the message starts with "groups"), a group that holds fields is dropped, the layout breaks the release contract\'s rules (the message starts with the path), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function saveLayout(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the layout as JSON.', self::MALFORMED_LAYOUT);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $groups = $body['groups'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($groups) || !$this->hasJsonObjectListAt($request, 'groups')) {
            return $this->error(self::MALFORMED_LAYOUT, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new SaveSheetLayout($user->id(), $key, $expectedVersion, $body['columns'] ?? null, $groups), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/layout/declare', name: 'api_studio_game_systems_sheet_layout_declare', methods: ['POST'])]
    #[OA\Post(operationId: 'declareSheetLayout', summary: 'Declare the layout of the sheet of one of my GameSystem drafts from the groups its fields name: one group each in order of first appearance, fields without a group in "General"')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: DeclareSheetLayoutRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the declared groups, its fields regrouped so each group is contiguous, and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "expectedVersion".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The sheet already declares a layout or has no fields (the message starts with "sheet.groups" or "sheet.fields"), the groups break the release contract\'s rules (more than 20), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function declareLayout(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the draft version as JSON.', self::MALFORMED_DECLARE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DECLARE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new DeclareSheetLayout($user->id(), $key, $expectedVersion), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/layout', name: 'api_studio_game_systems_sheet_layout_remove', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'removeSheetLayout', summary: 'Remove the layout of the sheet of one of my GameSystem drafts: the sheet\'s columns, the declared groups and every field\'s span; fields keep their group and order')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet without layout and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Another request saved the draft meanwhile; reload it and try again. A library pack refuses sheet fields and layouts.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The sheet declares no layout (the message starts with "sheet.groups"), the draft breaks the release contract\'s rules elsewhere (the message starts with the path), or it has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function removeLayout(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $this->expectedVersionParameter($request);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new RemoveSheetLayout($user->id(), $key, $expectedVersion), $user, $key, Response::HTTP_OK);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/point-budget', name: 'api_studio_game_systems_sheet_point_budget_save', methods: ['PUT'])]
    #[OA\Put(operationId: 'saveSheetPointBudget', summary: 'Replace the single point budget of the sheet of one of my GameSystem drafts: the total a Character spends over number fields at creation, with creation-only bounds for them. Answers 409 several_point_budgets when the sheet has several: use the keyed routes (kept until the Studio UI moves to them)')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveSheetPointBudgetRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the new point budget and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "expectedVersion".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Nothing changed; the code says why: modified_concurrently (another request saved the draft meanwhile: reload it and try again), library_pack (a pack has no sheet) or several_point_budgets (the sheet has several point budgets: use the keyed routes).', content: new OA\JsonContent(ref: new Model(type: SheetConflictResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The budget breaks the release contract\'s rules (the message starts with its path, "sheet.pointBudget" and the property: a missing or non-number total, a missing, non-list, unknown, non-number or repeated field, min above max, a total below the sum of the lowest values the fields can take), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function savePointBudget(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the point budget as JSON.', self::MALFORMED_POINT_BUDGET);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_POINT_BUDGET, Response::HTTP_BAD_REQUEST);
        }

        // What is left out or null is unset; the publisher's rules refuse a malformed or unknown property with its path.
        unset($body['expectedVersion']);
        $budget = array_filter($body, static fn (mixed $value): bool => null !== $value);

        return $this->write(new SaveSheetPointBudget($user->id(), $key, $expectedVersion, $budget), $user, $key, Response::HTTP_OK, coded: true);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/point-budget', name: 'api_studio_game_systems_sheet_point_budget_remove', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'removeSheetPointBudget', summary: 'Remove the single point budget of the sheet of one of my GameSystem drafts: Characters are created without one. Answers 409 several_point_budgets when the sheet has several: use the keyed routes (kept until the Studio UI moves to them)')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet without a point budget and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Nothing changed; the code says why: modified_concurrently (another request saved the draft meanwhile: reload it and try again), library_pack (a pack has no sheet) or several_point_budgets (the sheet has several point budgets: use the keyed routes).', content: new OA\JsonContent(ref: new Model(type: SheetConflictResponse::class)))]
    #[OA\Response(response: 422, description: 'The sheet has no point budget (the message starts with "sheet.pointBudget"), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function removePointBudget(string $key, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $this->expectedVersionParameter($request);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new RemoveSheetPointBudget($user->id(), $key, $expectedVersion), $user, $key, Response::HTTP_OK, coded: true);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/point-budgets/{budget}', name: 'api_studio_game_systems_sheet_point_budget_entry_save', methods: ['PUT'])]
    #[OA\Put(operationId: 'saveSheetPointBudgetEntry', summary: 'Put one point budget of the sheet of one of my GameSystem drafts by its key: replaces it in place, else appends it (a legacy single budget is the key "points")')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SaveSheetPointBudgetEntryRequest::class)))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet with the budget and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or has no integer "expectedVersion".', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Nothing changed; the code says why: modified_concurrently (another request saved the draft meanwhile: reload it and try again) or library_pack (a pack has no sheet).', content: new OA\JsonContent(ref: new Model(type: SheetConflictResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The list of budgets would break the release contract (the message starts with its path, "sheet.pointBudgets[i]" and the property: an invalid budget key, a missing label, a missing or non-number total, a missing, unknown, non-number, repeated or already budgeted field, min above max, a total below the sum of the lowest values the fields can take, more than 10 budgets, a label-less legacy budget that a second budget would leave unnamed), or the draft has schema version 1; nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function savePointBudgetEntry(string $key, string $budget, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the point budget as JSON.', self::MALFORMED_POINT_BUDGET_ENTRY);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_POINT_BUDGET_ENTRY, Response::HTTP_BAD_REQUEST);
        }

        // What is left out or null is unset; the publisher's rules refuse a malformed or unknown property with its path.
        unset($body['expectedVersion']);
        $entry = array_filter($body, static fn (mixed $value): bool => null !== $value);

        return $this->write(new SaveSheetPointBudgetEntry($user->id(), $key, $expectedVersion, $budget, $entry), $user, $key, Response::HTTP_OK, coded: true);
    }

    #[Route('/api/studio/game-systems/{key}/sheet/point-budgets/{budget}', name: 'api_studio_game_systems_sheet_point_budget_entry_remove', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'removeSheetPointBudgetEntry', summary: 'Delete one point budget of the sheet of one of my GameSystem drafts by its key; the last one removes the property')]
    #[OA\Parameter(name: 'expectedVersion', description: 'The draft version the client read; a query parameter because a DELETE has no body.', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3))]
    #[OA\Response(response: 200, description: 'The draft\'s sheet without the budget and the new version.', content: new OA\JsonContent(ref: new Model(type: GameSystemSheetResponse::class)))]
    #[OA\Response(response: 400, description: 'The "expectedVersion" query parameter is missing or not an integer.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not a game manager.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'The user has no GameSystem with this key, or its sheet has no point budget with this key.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'Nothing changed; the code says why: modified_concurrently (another request saved the draft meanwhile: reload it and try again) or library_pack (a pack has no sheet).', content: new OA\JsonContent(ref: new Model(type: SheetConflictResponse::class)))]
    #[OA\Response(response: 422, description: 'The draft has schema version 1, or the draft would break the release contract\'s rules elsewhere (the message starts with the path); nothing changed.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function removePointBudgetEntry(string $key, string $budget, Request $request, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $expectedVersion = $this->expectedVersionParameter($request);
        if (!\is_int($expectedVersion)) {
            return $this->error(self::MALFORMED_DELETE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new RemoveSheetPointBudgetEntry($user->id(), $key, $expectedVersion, $budget), $user, $key, Response::HTTP_OK, coded: true);
    }

    /**
     * Creates the field, or replaces the one named $originalKey.
     */
    private function save(string $key, ?string $originalKey, Request $request, AuthenticatedUser $user, int $status): JsonResponse
    {
        $body = $this->jsonBody($request, 'Send the field as JSON.', self::MALFORMED_SAVE);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $expectedVersion = $body['expectedVersion'] ?? null;
        $field = $body['field'] ?? null;
        if (!\is_int($expectedVersion) || !\is_array($field) || !$this->hasJsonObjectAt($request, 'field')) {
            return $this->error(self::MALFORMED_SAVE, Response::HTTP_BAD_REQUEST);
        }

        return $this->write(new SaveSheetField($user->id(), $key, $expectedVersion, $originalKey, $field), $user, $key, $status);
    }

    /**
     * Dispatches a sheet command and reads the sheet back in one transaction, and answers with the
     * sheet as they are now. The command's UPDATE holds the draft's row until the commit, so the read
     * sees exactly this write, never a save that interleaved, and a read that fails rolls the write
     * back, so the error answered is honest. The command bus's own transaction nests inside this one;
     * the refusals of the command roll both back and map as usual. The point budget routes answer
     * their 409s with a code ($coded, ADR 0037).
     */
    private function write(Command $command, AuthenticatedUser $user, string $key, int $status, bool $coded = false): JsonResponse
    {
        try {
            return $this->connection->transactional(function () use ($command, $user, $key, $status): JsonResponse {
                $this->commandBus->dispatch($command);

                return $this->sheetOf($user, $key, $status);
            });
        } catch (GameSystemNotFound|SheetFieldNotFound|SheetPointBudgetNotFound|GameSystemModifiedConcurrently|SheetFieldKeyTaken|SheetFieldInUse|LibraryPackRefuses|SeveralPointBudgets|InvalidReleaseContent $exception) {
            return $this->refusal($exception, $coded);
        }
    }

    /**
     * The "expectedVersion" query parameter of a DELETE, which has no body; null unless it is one
     * integer (an array-shaped parameter, a missing or an empty one is not).
     */
    private function expectedVersionParameter(Request $request): ?int
    {
        $raw = $request->query->all()['expectedVersion'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }

        $version = filter_var($raw, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);

        return \is_int($version) ? $version : null;
    }

    /**
     * Whether the body, a JSON object, holds a JSON list of JSON objects at this key. Request::toArray()
     * decodes `{}` and `[]` alike, so only the raw body tells a list from an object.
     */
    private function hasJsonObjectListAt(Request $request, string $key): bool
    {
        $body = json_decode($request->getContent(), false);
        $list = $body instanceof \stdClass ? ($body->{$key} ?? null) : null;
        if (!\is_array($list)) {
            return false;
        }

        return array_all($list, static fn ($item): bool => $item instanceof \stdClass);
    }

    /**
     * @throws GameSystemNotFound
     */
    private function sheetOf(AuthenticatedUser $user, string $key, int $status): JsonResponse
    {
        return new JsonResponse(GameSystemSheetResponse::fromView($this->queryBus->ask(new GetGameSystemSheet($user->id(), $key))), $status);
    }

    private function refusal(\Throwable $exception, bool $coded): JsonResponse
    {
        $code = match (true) {
            $exception instanceof GameSystemModifiedConcurrently => SheetConflictCode::ModifiedConcurrently,
            $exception instanceof LibraryPackRefuses => SheetConflictCode::LibraryPack,
            $exception instanceof SeveralPointBudgets => SheetConflictCode::SeveralPointBudgets,
            default => null,
        };
        if ($coded && null !== $code) {
            return new JsonResponse(SheetConflictResponse::with($code, $exception->getMessage()), Response::HTTP_CONFLICT);
        }

        return match (true) {
            $exception instanceof GameSystemNotFound, $exception instanceof SheetFieldNotFound, $exception instanceof SheetPointBudgetNotFound => $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND),
            $exception instanceof GameSystemModifiedConcurrently, $exception instanceof SheetFieldKeyTaken, $exception instanceof LibraryPackRefuses, $exception instanceof SeveralPointBudgets => $this->error($exception->getMessage(), Response::HTTP_CONFLICT),
            $exception instanceof SheetFieldInUse => new JsonResponse(OracleInUseResponse::fromException($exception), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }
}
