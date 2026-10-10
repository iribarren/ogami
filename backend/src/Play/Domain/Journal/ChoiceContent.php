<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * The option the player chose at a Flow step of kind choice: the question asked, the option's key
 * and its label.
 */
final readonly class ChoiceContent implements JournalEntryContent
{
    public const string KIND = 'choice';

    public const int MAX_QUESTION_LENGTH = 500;

    public const int MAX_LABEL_LENGTH = 100;

    private function __construct(
        private string $question,
        private string $optionKey,
        private string $label,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the trimmed question, option key or label is blank, the
     *                                    question is longer than 500 characters or the label than 100
     */
    public static function of(string $question, string $optionKey, string $label): self
    {
        [$question, $optionKey, $label] = [trim($question), trim($optionKey), trim($label)];
        foreach (['A choice question' => $question, 'A choice option key' => $optionKey, 'A choice label' => $label] as $what => $value) {
            if ('' === $value) {
                throw InvalidJournalEntryContent::blank($what);
            }
        }

        $length = mb_strlen($question);
        if ($length > self::MAX_QUESTION_LENGTH) {
            throw InvalidJournalEntryContent::questionTooLong(self::MAX_QUESTION_LENGTH, $length);
        }

        $length = mb_strlen($label);
        if ($length > self::MAX_LABEL_LENGTH) {
            throw InvalidJournalEntryContent::labelTooLong(self::MAX_LABEL_LENGTH, $length);
        }

        return new self($question, $optionKey, $label);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(ContentData::string($data, 'question'), ContentData::string($data, 'optionKey'), ContentData::string($data, 'label'));
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function question(): string
    {
        return $this->question;
    }

    public function optionKey(): string
    {
        return $this->optionKey;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array{kind: string, question: string, optionKey: string, label: string}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'question' => $this->question, 'optionKey' => $this->optionKey, 'label' => $this->label];
    }
}
