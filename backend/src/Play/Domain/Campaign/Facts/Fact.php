<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * A Campaign fact: worldbuilding the player keeps beside the journal. Either a free fact (a label
 * and a text the player writes) or a slot fact, which fills one Fact slot of the pinned release with
 * a text (a `text` slot) or with a subject, an NPC or a Thread (an `npc` / `thread` slot). Both may
 * carry Fact links to NPCs, Threads and other facts.
 *
 * The rules are checked here, so a stored fact read back is checked too. Whether the slot exists,
 * fits the value, and whether links and subject name things of the campaign is the Campaign's check.
 */
final readonly class Fact
{
    public const int MAX_LABEL_LENGTH = 100;

    public const int MAX_TEXT_LENGTH = 2000;

    public const int MAX_LINKS = 20;

    /**
     * @param list<FactLink> $links
     */
    private function __construct(
        private FactId $id,
        private ?string $slot,
        private ?string $label,
        private ?string $text,
        private ?FactLink $subject,
        private array $links,
    ) {
    }

    /**
     * A free fact has a label and a text and no slot or subject; a slot fact has a slot, no label,
     * and exactly one of a text and a subject (an NPC or a Thread).
     *
     * @param list<FactLink> $links
     *
     * @throws InvalidFact when a value breaks a rule
     */
    public static function of(FactId $id, ?string $slot, ?string $label, ?string $text, ?FactLink $subject, array $links): self
    {
        if (null === $slot) {
            if ($subject instanceof FactLink) {
                throw InvalidFact::because('A free fact has no subject.');
            }

            $label = self::bounded($label, 'label', self::MAX_LABEL_LENGTH);
            $text = self::bounded($text, 'text', self::MAX_TEXT_LENGTH);
        } else {
            if ('' === trim($slot)) {
                throw InvalidFact::because('The slot key must not be blank.');
            }

            if (null !== $label) {
                throw InvalidFact::because('A slot fact has no label; the slot has one.');
            }

            if ((null === $text) === (!$subject instanceof FactLink)) {
                throw InvalidFact::because('A slot fact holds a text or a subject, not both and not neither.');
            }

            if (FactLinkKind::Fact === $subject?->kind) {
                throw InvalidFact::because('A subject is an NPC or a Thread.');
            }

            $text = null === $text ? null : self::bounded($text, 'text', self::MAX_TEXT_LENGTH);
        }

        return new self($id, $slot, $label, $text, $subject, self::checkedLinks($id, $links));
    }

    /**
     * The same fact with new links, everything else kept.
     *
     * @param list<FactLink> $links
     *
     * @throws InvalidFact when a link breaks a rule
     */
    public function withLinks(array $links): self
    {
        return self::of($this->id, $this->slot, $this->label, $this->text, $this->subject, $links);
    }

    /**
     * The same free fact with a new label and text; a null keeps the current one. A slot fact stays
     * as it is when both are null.
     *
     * @throws InvalidFact when this is a slot fact and a label or text is given (its value changes by filling the slot again), or a value breaks a rule
     */
    public function edited(?string $label, ?string $text): self
    {
        if (null !== $this->slot) {
            if (null !== $label || null !== $text) {
                throw InvalidFact::because('A slot fact has no label or text of its own; fill its slot again to change its value.');
            }

            return $this;
        }

        return self::of($this->id, null, $label ?? $this->label, $text ?? $this->text, null, $this->links);
    }

    /**
     * The same slot fact with a new value, id and links kept.
     *
     * @throws InvalidFact when this is a free fact or the value breaks a rule
     */
    public function refilled(?string $text, ?FactLink $subject): self
    {
        if (null === $this->slot) {
            throw InvalidFact::because('A free fact has no slot to fill again.');
        }

        return self::of($this->id, $this->slot, null, $text, $subject, $this->links);
    }

    public function id(): FactId
    {
        return $this->id;
    }

    /**
     * @return ?string the Fact slot key of the pinned release; null for a free fact
     */
    public function slot(): ?string
    {
        return $this->slot;
    }

    public function isFree(): bool
    {
        return null === $this->slot;
    }

    /**
     * @return ?string the label of a free fact; null for a slot fact (the slot's label is shown)
     */
    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * @return ?string the text of a free fact or of a `text` slot; null when the slot holds a subject
     */
    public function text(): ?string
    {
        return $this->text;
    }

    /**
     * @return ?FactLink the NPC or Thread an `npc` / `thread` slot holds; null otherwise
     */
    public function subject(): ?FactLink
    {
        return $this->subject;
    }

    /**
     * @return list<FactLink>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return string the trimmed value
     */
    private static function bounded(?string $value, string $field, int $max): string
    {
        $value = trim($value ?? '');
        if ('' === $value) {
            throw InvalidFact::because(\sprintf('The %s must not be blank.', $field));
        }

        $length = mb_strlen($value);
        if ($length > $max) {
            throw InvalidFact::because(\sprintf('The %s must be at most %d characters, got %d.', $field, $max, $length));
        }

        return $value;
    }

    /**
     * @param list<FactLink> $links
     *
     * @return list<FactLink>
     */
    private static function checkedLinks(FactId $id, array $links): array
    {
        if (\count($links) > self::MAX_LINKS) {
            throw InvalidFact::because(\sprintf('At most %d links are allowed, got %d.', self::MAX_LINKS, \count($links)));
        }

        $seen = [];
        $self = FactLink::fact($id);
        foreach ($links as $link) {
            if ($link->equals($self)) {
                throw InvalidFact::because('A fact cannot link to itself.');
            }

            $key = $link->kind->value.':'.$link->id;
            if (isset($seen[$key])) {
                throw InvalidFact::because(\sprintf('The %s "%s" is linked twice.', $link->kind->value, $link->id));
            }

            $seen[$key] = true;
        }

        return $links;
    }
}
