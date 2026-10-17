<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * Free text: 200 characters by default, 2000 when multiline.
 */
final readonly class TextField extends Field
{
    public const int DEFAULT_LENGTH = 200;
    public const int MULTILINE_LENGTH = 2000;

    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public bool $multiline = false,
        public ?int $maxLength = null,
        int $span = 1,
    ) {
        parent::__construct($key, $label, $hint, $group, $span);
    }

    public function limit(): int
    {
        return $this->maxLength ?? ($this->multiline ? self::MULTILINE_LENGTH : self::DEFAULT_LENGTH);
    }
}
