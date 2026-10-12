<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The body to edit a Campaign fact. A free fact needs its label and text; a slot fact takes only
 * its links (its value changes by filling the slot again). Documents the contract only: the
 * controller reads the body from the request.
 */
#[OA\Schema(required: ['links'])]
final readonly class EditFactRequest
{
    /**
     * @param list<FactLinkRequest> $links
     */
    public function __construct(
        #[OA\Property(description: 'Required for a free fact: 1 to 100 characters once trimmed. Refused for a slot fact.', example: 'The guild', nullable: true)]
        public ?string $label,
        #[OA\Property(description: 'Required for a free fact: 1 to 2000 characters once trimmed. Refused for a slot fact.', example: 'Owes a debt to the Prince.', nullable: true)]
        public ?string $text,
        #[OA\Property(description: 'Replace the fact\'s links with these: at most 20, each once; send an empty list to clear them.', type: 'array', items: new OA\Items(ref: new Model(type: FactLinkRequest::class)))]
        public array $links,
    ) {
    }
}
