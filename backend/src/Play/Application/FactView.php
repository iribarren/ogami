<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * A Campaign fact: the slot it fills (null for a free fact), its label (the slot's for a slot fact),
 * its text or subject, its value (the text, or the subject's current name) and its links.
 */
final readonly class FactView
{
    /**
     * @param list<FactLinkView> $links
     */
    public function __construct(
        public string $id,
        public ?string $slot,
        public string $label,
        public ?string $text,
        public ?FactLinkView $subject,
        public string $value,
        public array $links,
    ) {
    }
}
