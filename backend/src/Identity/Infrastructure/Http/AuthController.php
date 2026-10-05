<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\GetUser;
use App\Identity\Application\UserView;
use App\Identity\Infrastructure\Security\JsonAuthenticationEntryPoint;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Application\Bus\QueryBus;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Session cookie authentication (ADR 0006). The `main` firewall handles login
 * (`json_login`) and logout before these actions run; their routes exist to
 * restrict the HTTP method and to document the contract.
 */
#[AsController]
#[OA\Tag(name: 'Auth')]
final readonly class AuthController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    /**
     * Reached only when the firewall skipped the request: it was not JSON.
     */
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    #[OA\Post(operationId: 'login', summary: 'Sign in and start a session')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: LoginRequest::class)))]
    #[OA\Response(
        response: 200,
        description: 'Signed in; the response sets the HttpOnly session cookie.',
        content: new OA\JsonContent(ref: new Model(type: CurrentUserResponse::class)),
    )]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or misses a field.')]
    #[OA\Response(response: 401, description: 'Invalid credentials.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 429, description: 'Too many failed attempts; try again later.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function login(): JsonResponse
    {
        return new JsonResponse(
            ErrorResponse::withMessage('Send the credentials as JSON.'),
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
        );
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    #[OA\Post(operationId: 'logout', summary: 'End the session')]
    #[OA\Response(response: 204, description: 'Signed out (also when there was no session).')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the logout listener of the security firewall.');
    }

    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    #[OA\Get(operationId: 'getCurrentUser', summary: 'Read the signed-in user')]
    #[OA\Response(
        response: 200,
        description: 'The signed-in user.',
        content: new OA\JsonContent(ref: new Model(type: CurrentUserResponse::class)),
    )]
    #[OA\Response(response: 401, description: 'No session.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function currentUser(#[CurrentUser] SecurityUser $user): JsonResponse
    {
        $view = $this->queryBus->ask(new GetUser($user->id()));
        if (!$view instanceof UserView) {
            return JsonAuthenticationEntryPoint::authenticationRequired();
        }

        return new JsonResponse(CurrentUserResponse::fromView($view));
    }
}
