<?php

declare(strict_types=1);

namespace App\Formula\Domain;

/**
 * A formula that parsed but cannot produce a value. The reason is a stable code shared with the
 * conformance vectors.
 */
final class FormulaEvaluationFailed extends \DomainException
{
    public const string DIVISION_BY_ZERO = 'divisionByZero';
    public const string OVERFLOW = 'overflow';
    public const string UNKNOWN_REFERENCE = 'unknownReference';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function divisionByZero(): self
    {
        return new self(self::DIVISION_BY_ZERO, 'The formula divides by zero.');
    }

    public static function overflow(): self
    {
        return new self(self::OVERFLOW, \sprintf('A result of the formula is outside %d to %d.', -Formula::MAX_MAGNITUDE, Formula::MAX_MAGNITUDE));
    }

    public static function unknownReference(Reference $reference): self
    {
        return new self(self::UNKNOWN_REFERENCE, \sprintf('The formula reads "%s", which has no value.', $reference->toString()));
    }
}
