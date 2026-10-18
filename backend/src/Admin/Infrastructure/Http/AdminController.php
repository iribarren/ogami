<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\InvalidUserInput;
use App\Identity\Application\InvitationAlreadyPending;
use App\Identity\Application\InvitationNotFound;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Application\MailUnavailable;
use App\Identity\Application\OwnAccountProtected;
use App\Identity\Application\UserDisabled;
use App\Identity\Application\UserNotFound;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Admin endpoints share: reading a strict JSON body and answering the Identity errors.
 * Every Admin route is owner-only in `access_control`.
 */
abstract readonly class AdminController
{
    protected const string NOT_JSON = 'Send the request as JSON.';

    /**
     * @param list<string> $fields    the keys the body must have, and no others
     * @param string       $malformed the error when the JSON is malformed, not an object or has other keys (400)
     *
     * @return array<mixed>|JsonResponse the decoded body, or the error response to send
     */
    protected function jsonBody(Request $request, array $fields, string $malformed): array|JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error(self::NOT_JSON, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $body = $request->toArray();
        } catch (JsonException) {
            return $this->error($malformed, Response::HTTP_BAD_REQUEST);
        }

        if ([] !== array_diff(array_keys($body), $fields) || [] !== array_diff($fields, array_keys($body))) {
            return $this->error($malformed, Response::HTTP_BAD_REQUEST);
        }

        return $body;
    }

    /**
     * @return list<string>|null the roles, or null when they are not a list of strings
     */
    protected function roles(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        foreach ($value as $role) {
            if (!\is_string($role)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * The response for an error an Identity command or query raises; any other error is not ours to hide.
     */
    protected function failure(\Throwable $exception): JsonResponse
    {
        $status = match (true) {
            $exception instanceof UserNotFound,
            $exception instanceof InvitationNotFound => Response::HTTP_NOT_FOUND,
            $exception instanceof OwnAccountProtected,
            $exception instanceof LastActiveOwner,
            $exception instanceof EmailAlreadyInUse,
            $exception instanceof InvitationAlreadyPending,
            $exception instanceof UserDisabled => Response::HTTP_CONFLICT,
            $exception instanceof MailUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            InvalidUserInput::is($exception) => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => throw $exception,
        };

        return $this->error($exception->getMessage(), $status);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
