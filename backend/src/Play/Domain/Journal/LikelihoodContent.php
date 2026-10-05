<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\YesNoAnswer;

/**
 * A likelihood oracle of the pinned release asked a yes/no question: the oracle, the optional
 * question and the answer, in the same shape as the Randomness likelihood answer view.
 */
final readonly class LikelihoodContent implements JournalEntryContent
{
    public const string KIND = 'likelihood';

    public const int MAX_QUESTION_LENGTH = 500;

    /**
     * @param string   $answer      "exceptional_yes", "yes", "no" or "exceptional_no"
     * @param string   $likelihood  the level key asked
     * @param int|null $chaosFactor null when the oracle has no chaos
     */
    private function __construct(
        private string $oracleKey,
        private string $oracleName,
        private ?string $question,
        private string $answer,
        private int $roll,
        private int $sides,
        private int $effectiveTarget,
        private string $likelihood,
        private string $likelihoodLabel,
        private ?int $chaosFactor,
    ) {
    }

    /**
     * @param string|null $question trimmed; blank means no question
     *
     * @throws InvalidJournalEntryContent when the oracle key is blank or the question is longer than 500 characters
     */
    public static function fromAnswer(string $oracleKey, string $oracleName, ?string $question, LikelihoodAnswer $answer): self
    {
        return self::of(
            $oracleKey,
            $oracleName,
            $question,
            $answer->answer(),
            $answer->roll(),
            $answer->sides(),
            $answer->effectiveTarget(),
            $answer->levelKey(),
            $answer->levelLabel(),
            $answer->chaosFactor(),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        $answer = YesNoAnswer::tryFrom(ContentData::string($data, 'answer'))
            ?? throw InvalidJournalEntryContent::malformed('answer', 'one of "exceptional_yes", "yes", "no", "exceptional_no"');

        return self::of(
            ContentData::string($data, 'oracleKey'),
            ContentData::string($data, 'oracleName'),
            ContentData::nullableString($data, 'question'),
            $answer,
            ContentData::int($data, 'roll'),
            ContentData::int($data, 'sides'),
            ContentData::int($data, 'effectiveTarget'),
            ContentData::string($data, 'likelihood'),
            ContentData::string($data, 'likelihoodLabel'),
            ContentData::nullableInt($data, 'chaosFactor'),
        );
    }

    private static function of(
        string $oracleKey,
        string $oracleName,
        ?string $question,
        YesNoAnswer $answer,
        int $roll,
        int $sides,
        int $effectiveTarget,
        string $likelihood,
        string $likelihoodLabel,
        ?int $chaosFactor,
    ): self {
        if ('' === trim($oracleKey)) {
            throw InvalidJournalEntryContent::blankOracleKey();
        }

        return new self(
            $oracleKey,
            $oracleName,
            self::normalizeQuestion($question),
            $answer->value,
            $roll,
            $sides,
            $effectiveTarget,
            $likelihood,
            $likelihoodLabel,
            $chaosFactor,
        );
    }

    private static function normalizeQuestion(?string $question): ?string
    {
        $question = trim($question ?? '');
        if ('' === $question) {
            return null;
        }

        $length = mb_strlen($question);
        if ($length > self::MAX_QUESTION_LENGTH) {
            throw InvalidJournalEntryContent::questionTooLong(self::MAX_QUESTION_LENGTH, $length);
        }

        return $question;
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function oracleKey(): string
    {
        return $this->oracleKey;
    }

    public function oracleName(): string
    {
        return $this->oracleName;
    }

    public function question(): ?string
    {
        return $this->question;
    }

    /**
     * @return array{kind: string, oracleKey: string, oracleName: string, question: ?string, answer: string, roll: int, sides: int, effectiveTarget: int, likelihood: string, likelihoodLabel: string, chaosFactor: ?int}
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'oracleKey' => $this->oracleKey,
            'oracleName' => $this->oracleName,
            'question' => $this->question,
            'answer' => $this->answer,
            'roll' => $this->roll,
            'sides' => $this->sides,
            'effectiveTarget' => $this->effectiveTarget,
            'likelihood' => $this->likelihood,
            'likelihoodLabel' => $this->likelihoodLabel,
            'chaosFactor' => $this->chaosFactor,
        ];
    }
}
