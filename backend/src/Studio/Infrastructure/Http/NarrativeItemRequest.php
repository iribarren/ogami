<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The item of a narrative command: a tracker, tag, fact slot, Scene Type or flow as in a release
 * document. Documents the contract only: the publisher's rules validate it, so the schema is free-form.
 */
#[OA\Schema(type: 'object', example: ['key' => 'gang', 'label' => 'Gang'], additionalProperties: true)]
final readonly class NarrativeItemRequest
{
}
