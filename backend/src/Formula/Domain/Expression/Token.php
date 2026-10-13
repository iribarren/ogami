<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\Reference;

final readonly class Token
{
    /**
     * @param string     $text      the token as written
     * @param int        $position  the 1-based position of its first character in the formula
     * @param ?Reference $reference the parsed reference of a Reference token
     */
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $position,
        public ?Reference $reference = null,
    ) {
    }

    /**
     * The integer value of an Integer token, saturated at PHP_INT_MAX.
     */
    public function integer(): int
    {
        return (int) $this->text;
    }
}
