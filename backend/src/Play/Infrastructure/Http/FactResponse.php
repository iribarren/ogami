<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FactLinkView;
use App\Play\Application\FactView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Campaign fact: free (a label and a text) or filling a Fact slot of the pinned release.
 */
#[OA\Schema(required: ['id', 'slot', 'label', 'text', 'subject', 'value', 'links'])]
final readonly class FactResponse
{
    /**
     * @param list<FactLinkResponse> $links
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(description: 'The key of the Fact slot the fact fills; null for a free fact.', example: 'prince', nullable: true)]
        public ?string $slot,
        #[OA\Property(description: 'The label the player wrote; for a slot fact, the slot\'s label.', example: 'The guild')]
        public string $label,
        #[OA\Property(description: 'The text of a free fact or of a "text" slot; null when the slot holds an NPC or a Thread.', example: 'Owes a debt to the Prince.', nullable: true)]
        public ?string $text,
        #[OA\Property(ref: new Model(type: FactLinkResponse::class), description: 'The NPC or Thread an "npc" / "thread" slot holds; null otherwise.', nullable: true)]
        public ?FactLinkResponse $subject,
        #[OA\Property(description: 'What the fact says: its text, or its subject\'s name now.', example: 'Owes a debt to the Prince.')]
        public string $value,
        #[OA\Property(
            description: 'What the fact links to, in the order the player gave.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: FactLinkResponse::class)),
        )]
        public array $links,
    ) {
    }

    public static function fromView(FactView $view): self
    {
        return new self(
            $view->id,
            $view->slot,
            $view->label,
            $view->text,
            $view->subject instanceof FactLinkView ? FactLinkResponse::fromView($view->subject) : null,
            $view->value,
            array_map(FactLinkResponse::fromView(...), $view->links),
        );
    }
}
