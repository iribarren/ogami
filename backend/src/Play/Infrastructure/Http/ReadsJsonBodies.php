<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ErrorResponse;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Play controllers share: reading a JSON request body and answering with an
 * ErrorResponse.
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
}
