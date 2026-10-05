<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\GetUser;
use App\Identity\Application\UserView;
use App\Identity\Infrastructure\Http\CurrentUserResponse;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Answers a successful `POST /api/auth/login` with the signed-in user, the
 * same body as `GET /api/auth/me`.
 */
final readonly class JsonAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $user = $token->getUser();
        \assert($user instanceof SecurityUser);

        $view = $this->queryBus->ask(new GetUser($user->id()));
        \assert($view instanceof UserView, 'The user was loaded for this very login.');

        return new JsonResponse(CurrentUserResponse::fromView($view));
    }
}
