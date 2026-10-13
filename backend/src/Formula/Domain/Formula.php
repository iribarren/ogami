<?php

declare(strict_types=1);

namespace App\Formula\Domain;

use App\Formula\Domain\Expression\Node;
use App\Formula\Domain\Expression\Parser;

/**
 * A parsed formula such as "({dex}-10)/2", "max({str},{dex})" or "{tracker:chaos}*2+{hp.max}".
 *
 * Grammar (whitespace ignored between tokens, never inside braces):
 *
 *     expression := term (("+" | "-") term)*
 *     term       := unary (("*" | "/") unary)*
 *     unary      := "-" unary | primary
 *     primary    := integer | reference | call | "(" expression ")"
 *     call       := ("min" | "max") "(" expression ("," expression)+ ")"
 *     reference  := "{" key "}" | "{" key ".max" "}" | "{tracker:" key "}"
 *
 * Division rounds down. Integers are written without a sign, from 0 to MAX_INTEGER. Every intermediate
 * result stays within ±MAX_MAGNITUDE, so PHP and JavaScript compute the same exact integers.
 * See ADR 0023.
 */
final readonly class Formula implements \Stringable
{
    public const int MAX_LENGTH = 200;
    public const int MAX_INTEGER = 1_000_000;
    public const int MAX_MAGNITUDE = 1_000_000_000;
    public const int MIN_ARGUMENTS = 2;
    public const int MAX_ARGUMENTS = 10;
    public const int MAX_DEPTH = 50;

    /**
     * @param list<Reference> $references
     */
    private function __construct(
        private string $text,
        private Node $root,
        private array $references,
    ) {
    }

    /**
     * @throws InvalidFormula
     */
    public static function fromString(string $formula): self
    {
        $text = trim($formula);
        if ('' === $text) {
            throw InvalidFormula::empty();
        }

        if (\strlen($text) > self::MAX_LENGTH) {
            throw InvalidFormula::tooLong(\strlen($text), self::MAX_LENGTH);
        }

        $root = Parser::parse($text);

        return new self($text, $root, self::unique($root->references()));
    }

    /**
     * @throws FormulaEvaluationFailed on division by zero, a result outside ±MAX_MAGNITUDE or a value the resolver lacks
     */
    public function evaluate(ValueResolver $resolver): int
    {
        return $this->root->evaluate($resolver);
    }

    /**
     * What the formula reads, each reference once, in the order it first appears.
     *
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * The original text, trimmed.
     */
    public function toString(): string
    {
        return $this->text;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    /**
     * @param list<Reference> $references
     *
     * @return list<Reference>
     */
    private static function unique(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            // The canonical spelling tells the kinds apart: "a", "a.max" and "tracker:a".
            $unique[$reference->toString()] ??= $reference;
        }

        return array_values($unique);
    }
}
