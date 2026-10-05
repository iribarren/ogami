<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;

/**
 * Recursive-descent parser from dice notation to an expression tree; see
 * {@see DiceExpression} for the grammar.
 */
final class Parser
{
    private const string OPERAND = 'a number, a die or "("';

    private int $current = 0;

    /**
     * @param list<Token> $tokens
     */
    private function __construct(
        private readonly array $tokens,
    ) {
    }

    /**
     * @throws InvalidDiceExpression
     */
    public static function parse(string $notation): Node
    {
        $parser = new self(Tokenizer::tokenize($notation));
        $root = $parser->expression();
        $parser->expect(TokenType::End, 'an operator or the end of the expression');

        return $root;
    }

    private function expression(): Node
    {
        $node = $this->term();
        while (($operator = $this->operator(Operator::Plus, Operator::Minus)) instanceof Operator) {
            $node = new BinaryOperation($node, $operator, $this->term());
        }

        return $node;
    }

    private function term(): Node
    {
        $node = $this->unary();
        while (($operator = $this->operator(Operator::Times, Operator::DividedBy)) instanceof Operator) {
            $node = new BinaryOperation($node, $operator, $this->unary());
        }

        return $node;
    }

    private function unary(): Node
    {
        if ($this->operator(Operator::Minus) instanceof Operator) {
            return new Negation($this->unary());
        }

        return $this->primary();
    }

    private function primary(): Node
    {
        $token = $this->peek();

        if (TokenType::OpenParenthesis === $token->type) {
            $this->advance();
            $inner = $this->expression();
            $this->expect(TokenType::CloseParenthesis, '")"');

            return new Parenthesized($inner);
        }

        if (TokenType::Dice === $token->type) {
            return $this->dice(1);
        }

        $integer = $this->expect(TokenType::Integer, self::OPERAND);
        if (TokenType::Dice === $this->peek()->type) {
            return $this->dice($integer->integer());
        }

        if ($integer->integer() > DiceExpression::MAX_INTEGER) {
            throw InvalidDiceExpression::integerOutOfRange(ltrim($integer->text, '0'), DiceExpression::MAX_INTEGER);
        }

        return new Constant($integer->integer());
    }

    private function dice(int $count): Dice
    {
        $this->expect(TokenType::Dice, '"d"');

        if (TokenType::Percent === $this->peek()->type) {
            $this->advance();
            $sides = 100;
        } else {
            $sides = $this->expect(TokenType::Integer, 'the number of sides or "%"')->integer();
        }

        if (TokenType::Selector !== $this->peek()->type) {
            return new Dice($count, $sides);
        }

        $selector = Selector::fromToken($this->advance()->text);
        $selectCount = $this->expect(TokenType::Integer, 'how many dice to keep or drop')->integer();

        return new Dice($count, $sides, $selector, $selectCount);
    }

    /**
     * Consumes the next token when it is one of the given operators.
     */
    private function operator(Operator ...$operators): ?Operator
    {
        $token = $this->peek();
        if (TokenType::Operator !== $token->type) {
            return null;
        }

        $operator = Operator::from($token->text);
        if (!\in_array($operator, $operators, true)) {
            return null;
        }

        $this->advance();

        return $operator;
    }

    private function expect(TokenType $type, string $expected): Token
    {
        $token = $this->peek();
        if ($type === $token->type) {
            return $this->advance();
        }

        if (TokenType::End === $token->type) {
            throw InvalidDiceExpression::unexpectedEnd($expected);
        }

        throw InvalidDiceExpression::unexpectedToken($token->text, $token->position, $expected);
    }

    private function peek(): Token
    {
        return $this->tokens[$this->current];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->current];
        if (TokenType::End !== $token->type) {
            ++$this->current;
        }

        return $token;
    }
}
