<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Studio controllers share: reading a JSON request body and answering with an
 * ErrorResponse. Play keeps its own copy: a context never imports another's Infrastructure.
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

    /**
     * Whether the body, a JSON object, holds a JSON object at this key. Request::toArray() decodes
     * `{}` and `[]` alike, so only the raw body tells them apart.
     */
    private function hasJsonObjectAt(Request $request, string $key): bool
    {
        $body = json_decode($request->getContent(), false);

        return $body instanceof \stdClass && ($body->{$key} ?? null) instanceof \stdClass;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
