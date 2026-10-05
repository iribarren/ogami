<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Answers a failed `POST /api/auth/login`. The message never tells whether the
 * email exists: a wrong password and an unknown email look the same.
 */
final readonly class JsonAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return new JsonResponse(
                ErrorResponse::withMessage('Too many login attempts. Try again later.'),
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        return new JsonResponse(ErrorResponse::withMessage('Invalid credentials.'), Response::HTTP_UNAUTHORIZED);
    }
}
