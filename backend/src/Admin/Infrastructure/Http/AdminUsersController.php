<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\ChangeUserRoles;
use App\Identity\Application\DisableUser;
use App\Identity\Application\EnableUser;
use App\Identity\Application\GetUser;
use App\Identity\Application\ListUsers;
use App\Identity\Application\PasswordResetIdGenerator;
use App\Identity\Application\SendPasswordReset;
use App\Identity\Application\UserNotFound;
use App\Identity\Application\UserView;
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
 * The owner's user management: list users, change roles, disable and enable accounts, send a
 * password reset link (ADR 0034). Owner-only in `access_control`.
 */
#[AsController]
#[OA\Tag(name: 'Admin')]
final readonly class AdminUsersController extends AdminController
{
    private const string MALFORMED_ROLES = 'Send a JSON object with a list of role strings, such as {"roles": ["SOLO_PLAYER"]}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private PasswordResetIdGenerator $resetIds,
    ) {
    }

    #[Route('/api/admin/users', name: 'api_admin_users_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listUsers', summary: 'List every user')]
    #[OA\Response(
        response: 200,
        description: 'Every user, ordered by email.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: AdminUserResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(AdminUserResponse::fromView(...), $this->queryBus->ask(new ListUsers())));
    }

    #[Route('/api/admin/users/{id}/roles', name: 'api_admin_users_roles', methods: ['PUT'])]
    #[OA\Put(operationId: 'changeUserRoles', summary: 'Replace the roles of a user')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ChangeUserRolesRequest::class)))]
    #[OA\Response(response: 200, description: 'The user with its new roles. The user is signed out, as Symfony Security does when roles change.', content: new OA\JsonContent(ref: new Model(type: AdminUserResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, misses the roles or has other fields.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No user has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The owner would remove their own OWNER role, or the last active owner would lose it.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'No role, or a value that is not a role.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function changeRoles(string $id, Request $request, #[CurrentUser] AuthenticatedUser $actor): JsonResponse
    {
        $body = $this->jsonBody($request, ['roles'], self::MALFORMED_ROLES);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $roles = $this->roles($body['roles']);
        if (null === $roles) {
            return $this->error(self::MALFORMED_ROLES, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ChangeUserRoles($actor->id(), $id, $roles));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        $view = $this->queryBus->ask(new GetUser($id));

        return $view instanceof UserView ? new JsonResponse(AdminUserResponse::fromView($view)) : $this->failure(UserNotFound::withId($id));
    }

    #[Route('/api/admin/users/{id}/disable', name: 'api_admin_users_disable', methods: ['POST'])]
    #[OA\Post(operationId: 'disableUser', summary: 'Stop a user from signing in')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'The user is disabled and loses any open session; their data stays. Disabling a disabled user changes nothing.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No user has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The owner would disable their own account, or the last active owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function disable(string $id, #[CurrentUser] AuthenticatedUser $actor): Response
    {
        try {
            $this->commandBus->dispatch(new DisableUser($actor->id(), $id));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/users/{id}/enable', name: 'api_admin_users_enable', methods: ['POST'])]
    #[OA\Post(operationId: 'enableUser', summary: 'Let a disabled user sign in again')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'The user can sign in. Enabling an active user changes nothing.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No user has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function enable(string $id): Response
    {
        try {
            $this->commandBus->dispatch(new EnableUser($id));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/users/{id}/password-reset', name: 'api_admin_users_password_reset', methods: ['POST'])]
    #[OA\Post(operationId: 'sendPasswordReset', summary: 'Email a user a link to choose a new password')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 202, description: 'The link is stored and mailed. It is valid for one hour.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No user has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The user is disabled: enable the account first.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 503, description: 'The email could not be sent. The reset stays stored; send it again.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function sendPasswordReset(string $id): Response
    {
        try {
            $this->commandBus->dispatch(new SendPasswordReset($this->resetIds->generate()->toString(), $id));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_ACCEPTED);
    }
}
