<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The body to add a free Campaign fact. Documents the contract only: the controller reads the body
 * from the request (an omitted or null "links" is none).
 */
#[OA\Schema(required: ['label', 'text'])]
final readonly class FactRequest
{
    /**
     * @param ?list<FactLinkRequest> $links
     */
    public function __construct(
        #[OA\Property(description: '1 to 100 characters once trimmed.', example: 'The guild')]
        public string $label,
        #[OA\Property(description: '1 to 2000 characters once trimmed.', example: 'Owes a debt to the Prince.')]
        public string $text,
        #[OA\Property(description: 'At most 20, each once; none when omitted or null.', type: 'array', items: new OA\Items(ref: new Model(type: FactLinkRequest::class)), nullable: true)]
        public ?array $links,
    ) {
    }
}
