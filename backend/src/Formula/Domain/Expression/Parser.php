<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;

/**
 * Recursive-descent parser from a formula to an expression tree; see {@see Formula} for the grammar.
 */
final class Parser
{
    private const string OPERAND = 'a number, a reference, "min", "max", "-" or "("';

    private int $current = 0;
    private int $depth = 0;

    /**
     * @param list<Token> $tokens
     */
    private function __construct(
        private readonly array $tokens,
    ) {
    }

    /**
     * @throws InvalidFormula
     */
    public static function parse(string $formula): Node
    {
        $parser = new self(Tokenizer::tokenize($formula));
        $root = $parser->expression();
        $parser->expect(TokenType::End, 'an operator or the end of the formula');

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
        if (!$this->operator(Operator::Minus) instanceof Operator) {
            return $this->primary();
        }

        $this->descend();
        $operand = $this->unary();
        --$this->depth;

        return new Negation($operand);
    }

    private function primary(): Node
    {
        $token = $this->peek();

        if (TokenType::OpenParenthesis === $token->type) {
            $this->advance();
            $inner = $this->nested();
            $this->expect(TokenType::CloseParenthesis, '")"');

            return $inner;
        }

        if (TokenType::Function === $token->type) {
            return $this->call();
        }

        if (TokenType::Reference === $token->type && $token->reference instanceof \App\Formula\Domain\Reference) {
            $this->advance();

            return new ReferenceNode($token->reference);
        }

        $integer = $this->expect(TokenType::Integer, self::OPERAND);
        if ($integer->integer() > Formula::MAX_INTEGER) {
            throw InvalidFormula::integerOutOfRange(ltrim($integer->text, '0'), Formula::MAX_INTEGER);
        }

        return new Constant($integer->integer());
    }

    private function call(): Call
    {
        $function = $this->advance();
        $this->expect(TokenType::OpenParenthesis, '"("');

        $arguments = [$this->nested()];
        while (TokenType::Comma === $this->peek()->type) {
            $this->advance();
            if (Formula::MAX_ARGUMENTS === \count($arguments)) {
                throw InvalidFormula::argumentCountOutOfRange($function->text, Formula::MIN_ARGUMENTS, Formula::MAX_ARGUMENTS, null);
            }

            $arguments[] = $this->nested();
        }

        $this->expect(TokenType::CloseParenthesis, '"," or ")"');
        if (\count($arguments) < Formula::MIN_ARGUMENTS) {
            throw InvalidFormula::argumentCountOutOfRange($function->text, Formula::MIN_ARGUMENTS, Formula::MAX_ARGUMENTS, \count($arguments));
        }

        return new Call($function->text, $arguments);
    }

    /**
     * An expression one level deeper, inside parentheses or a call.
     */
    private function nested(): Node
    {
        $this->descend();
        $node = $this->expression();
        --$this->depth;

        return $node;
    }

    private function descend(): void
    {
        if (++$this->depth > Formula::MAX_DEPTH) {
            throw InvalidFormula::tooDeep(Formula::MAX_DEPTH);
        }
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
            throw InvalidFormula::unexpectedEnd($expected);
        }

        throw InvalidFormula::unexpectedToken($token->text, $token->position, $expected);
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
