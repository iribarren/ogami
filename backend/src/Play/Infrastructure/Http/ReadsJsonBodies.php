<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Play controllers share: reading a JSON request body, checking a list of strings and
 * answering with an ErrorResponse.
 */
trait ReadsJsonBodies
{
    /**
     * @param string $notJson   the error when the body is not JSON (415)
     * @param string $malformed the error when the JSON is malformed or not an object (400)
     *
     * @return array<mixed>|JsonResponse the decoded body, or the error response to send
     */
    private function jsonBody(Request $request, string $notJson, string $malformed): array|JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error($notJson, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->error($malformed, Response::HTTP_BAD_REQUEST);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private function isListOfStrings(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_string($item));
    }

    /**
     * The campaign's pinned release cannot be read (gone, unsupported or broken): a server-side
     * fault, answered without its internals.
     */
    private function unreadableRelease(): JsonResponse
    {
        return $this->error('The GameSystem release this campaign plays can no longer be read.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
