<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

final class InvalidJournalEntryContent extends \DomainException
{
    public static function blankNote(): self
    {
        return new self('A note must not be blank.');
    }

    public static function noteTooLong(int $maxLength, int $length): self
    {
        return new self(\sprintf('A note must be at most %d characters, got %d.', $maxLength, $length));
    }

    public static function questionTooLong(int $maxLength, int $length): self
    {
        return new self(\sprintf('A question must be at most %d characters, got %d.', $maxLength, $length));
    }

    /**
     * @param string $what e.g. "A choice label"
     */
    public static function blank(string $what): self
    {
        return new self(\sprintf('%s must not be blank.', $what));
    }

    public static function labelTooLong(int $maxLength, int $length): self
    {
        return new self(\sprintf('A choice label must be at most %d characters, got %d.', $maxLength, $length));
    }

    public static function blankOracleKey(): self
    {
        return new self('An oracle key must not be blank.');
    }

    public static function unknownKind(string $kind): self
    {
        return new self(\sprintf('Unknown journal entry content kind "%s".', $kind));
    }

    public static function malformed(string $field, string $expected): self
    {
        return new self(\sprintf('Malformed journal entry content: "%s" must be %s.', $field, $expected));
    }
}
