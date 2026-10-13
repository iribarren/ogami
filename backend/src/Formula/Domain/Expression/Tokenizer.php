<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;

final class Tokenizer
{
    private const string PATTERN = '/\G(?:(?<space>[ \t\r\n]+)|(?<integer>[0-9]+)|(?<name>[A-Za-z][A-Za-z0-9_]*)|(?<reference>\{[^{}]*\})|(?<operator>[-+*\/])|(?<open>\()|(?<close>\))|(?<comma>,))/';

    private const array TYPES = [
        'integer' => TokenType::Integer,
        'operator' => TokenType::Operator,
        'open' => TokenType::OpenParenthesis,
        'close' => TokenType::CloseParenthesis,
        'comma' => TokenType::Comma,
    ];

    /**
     * Splits a formula into tokens, skipping whitespace; the list always ends with an End token.
     *
     * @return list<Token>
     *
     * @throws InvalidFormula on a character that belongs to no token, an unknown name or a malformed reference
     */
    public static function tokenize(string $formula): array
    {
        $length = \strlen($formula);
        $tokens = [];
        $offset = 0;

        while ($offset < $length) {
            if (1 !== preg_match(self::PATTERN, $formula, $matches, \PREG_UNMATCHED_AS_NULL, $offset)) {
                if ('{' === $formula[$offset]) {
                    throw InvalidFormula::unclosedReference($offset + 1);
                }

                throw InvalidFormula::unexpectedCharacter(self::characterAt($formula, $offset), $offset + 1);
            }

            $position = $offset + 1;
            $offset += \strlen($matches[0]);

            foreach (self::TYPES as $group => $type) {
                if (null !== $matches[$group]) {
                    $tokens[] = new Token($type, $matches[$group], $position);
                }
            }

            if (null !== $matches['name']) {
                $tokens[] = self::function($matches['name'], $position);
            }

            if (null !== $matches['reference']) {
                $tokens[] = self::reference($matches['reference'], $position);
            }
        }

        $tokens[] = new Token(TokenType::End, '', $length + 1);

        return $tokens;
    }

    private static function function(string $name, int $position): Token
    {
        if (!\in_array($name, ['min', 'max'], true)) {
            throw InvalidFormula::unknownName($name, $position);
        }

        return new Token(TokenType::Function, $name, $position);
    }

    private static function reference(string $text, int $position): Token
    {
        $reference = Reference::fromInner(substr($text, 1, -1));
        if (!$reference instanceof Reference) {
            throw InvalidFormula::invalidReference($text, $position);
        }

        return new Token(TokenType::Reference, $text, $position, $reference);
    }

    /**
     * The whole UTF-8 character at a byte offset, or its first byte as "\xNN" when it is not valid UTF-8,
     * so error messages always stay valid UTF-8.
     */
    private static function characterAt(string $formula, int $offset): string
    {
        if (1 === preg_match('/\G./su', $formula, $match, 0, $offset)) {
            return $match[0];
        }

        return \sprintf('\x%02X', \ord($formula[$offset]));
    }
}
