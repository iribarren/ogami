<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * A slot fact whose slot the new release does not have: it goes on as a free fact with this label
 * and text (its links stay).
 */
final readonly class UnslottedFact
{
    public function __construct(
        public string $factId,
        public string $slotKey,
        public string $label,
        public string $text,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['factId' => $this->factId, 'slotKey' => $this->slotKey, 'label' => $this->label, 'text' => $this->text];
    }
}
