<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\InvalidDiceExpression;

final class Tokenizer
{
    private const string PATTERN = '/\G(?:(?<space>\s+)|(?<integer>\d+)|(?<selector>kh|kl|dh|dl|k)|(?<dice>d)|(?<percent>%)|(?<operator>[-+*\/])|(?<open>\()|(?<close>\)))/';

    private const array TYPES = [
        'integer' => TokenType::Integer,
        'selector' => TokenType::Selector,
        'dice' => TokenType::Dice,
        'percent' => TokenType::Percent,
        'operator' => TokenType::Operator,
        'open' => TokenType::OpenParenthesis,
        'close' => TokenType::CloseParenthesis,
    ];

    /**
     * Splits a notation into tokens, skipping whitespace; the list always ends with an End token.
     *
     * @return list<Token>
     *
     * @throws InvalidDiceExpression on a character that belongs to no token
     */
    public static function tokenize(string $notation): array
    {
        $notation = strtolower($notation);
        $length = \strlen($notation);
        $tokens = [];
        $offset = 0;

        while ($offset < $length) {
            if (1 !== preg_match(self::PATTERN, $notation, $matches, \PREG_UNMATCHED_AS_NULL, $offset)) {
                throw InvalidDiceExpression::unexpectedCharacter($notation[$offset], $offset + 1);
            }

            foreach (self::TYPES as $group => $type) {
                if (null !== $matches[$group]) {
                    $tokens[] = new Token($type, $matches[$group], $offset + 1);
                }
            }

            $offset += \strlen($matches[0]);
        }

        $tokens[] = new Token(TokenType::End, '', $length + 1);

        return $tokens;
    }
}
