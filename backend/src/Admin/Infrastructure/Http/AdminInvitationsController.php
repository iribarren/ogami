<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\InvitationIdGenerator;
use App\Identity\Application\InviteUser;
use App\Identity\Application\ListInvitations;
use App\Identity\Application\ResendInvitation;
use App\Identity\Application\RevokeInvitation;
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
 * The owner's invitations: list the pending ones, invite an email, resend and revoke (ADR 0034).
 * Owner-only in `access_control`. The link and its token only ever travel by email.
 */
#[AsController]
#[OA\Tag(name: 'Admin')]
final readonly class AdminInvitationsController extends AdminController
{
    private const string MALFORMED_INVITATION = 'Send a JSON object with a string "email" and a list of role strings, such as {"email": "ada@example.com", "roles": ["SOLO_PLAYER"]}.';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private InvitationIdGenerator $invitationIds,
    ) {
    }

    #[Route('/api/admin/invitations', name: 'api_admin_invitations_list', methods: ['GET'])]
    #[OA\Get(operationId: 'listInvitations', summary: 'List the pending invitations')]
    #[OA\Response(
        response: 200,
        description: 'The pending invitations, ordered by email; an expired one is flagged and can be resent.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: AdminInvitationResponse::class))),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(AdminInvitationResponse::fromView(...), $this->queryBus->ask(new ListInvitations())));
    }

    #[Route('/api/admin/invitations', name: 'api_admin_invitations_invite', methods: ['POST'])]
    #[OA\Post(operationId: 'inviteUser', summary: 'Invite an email to create an account')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: InviteUserRequest::class)))]
    #[OA\Response(response: 201, description: 'The invitation, mailed with its link. It is valid for seven days.', content: new OA\JsonContent(ref: new Model(type: AdminInvitationResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed, misses a field or has other fields.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'The email has an account, or a pending invitation that has not expired.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The email is not a valid address, or there is no role, or a value is not a role.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 503, description: 'The email could not be sent. The invitation stays; resend it.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function invite(Request $request, #[CurrentUser] AuthenticatedUser $actor): JsonResponse
    {
        $body = $this->jsonBody($request, ['email', 'roles'], self::MALFORMED_INVITATION);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $email = $body['email'];
        $roles = $this->roles($body['roles']);
        if (!\is_string($email) || null === $roles) {
            return $this->error(self::MALFORMED_INVITATION, Response::HTTP_BAD_REQUEST);
        }

        $id = $this->invitationIds->generate()->toString();
        try {
            $this->commandBus->dispatch(new InviteUser($id, $email, $roles, $actor->id()));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        foreach ($this->queryBus->ask(new ListInvitations()) as $view) {
            if ($view->id === $id) {
                return new JsonResponse(AdminInvitationResponse::fromView($view), Response::HTTP_CREATED);
            }
        }

        throw new \LogicException('The invitation just made is not pending.');
    }

    #[Route('/api/admin/invitations/{id}/resend', name: 'api_admin_invitations_resend', methods: ['POST'])]
    #[OA\Post(operationId: 'resendInvitation', summary: 'Send a pending invitation again with a new link')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 202, description: 'A new link replaces the old one and is mailed; the invitation is valid for a full period from now.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No pending invitation has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 503, description: 'The email could not be sent. The invitation stays; resend it.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function resend(string $id): Response
    {
        try {
            $this->commandBus->dispatch(new ResendInvitation($id));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/admin/invitations/{id}', name: 'api_admin_invitations_revoke', methods: ['DELETE'])]
    #[OA\Delete(operationId: 'revokeInvitation', summary: 'Revoke a pending invitation')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 204, description: 'The invitation is revoked: its link no longer works.')]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 403, description: 'The user is not an owner.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 404, description: 'No pending invitation has this id (a malformed id included).', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function revoke(string $id): Response
    {
        try {
            $this->commandBus->dispatch(new RevokeInvitation($id));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
