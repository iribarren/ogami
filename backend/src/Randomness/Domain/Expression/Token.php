<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

final readonly class Token
{
    /**
     * @param string $text     the token as written, in lower case
     * @param int    $position the 1-based position of its first character in the original notation
     */
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $position,
    ) {
    }

    /**
     * The integer value of an Integer token, saturated at PHP_INT_MAX.
     */
    public function integer(): int
    {
        return (int) $this->text;
    }

    /**
     * The digits of an Integer token as written, without leading zeros.
     */
    public function written(): string
    {
        $digits = ltrim($this->text, '0');

        return '' === $digits ? '0' : $digits;
    }

    /**
     * Whether integer() lost the value because it does not fit in a PHP integer.
     */
    public function saturated(): bool
    {
        return (string) $this->integer() !== $this->written();
    }
}
