<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * An API call without a session gets a JSON 401, never a redirect.
 */
final readonly class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return self::authenticationRequired();
    }

    public static function authenticationRequired(): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage('Authentication required.'), Response::HTTP_UNAUTHORIZED);
    }
}
