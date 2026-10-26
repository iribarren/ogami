<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * The push a saved pool check declares (ADR 0041), as far as a test roll shows it: its label. The
 * cost is never applied in a test roll, so its effects are not read.
 */
final readonly class CheckTestPush
{
    public const int MAX_LABEL = 100;
    public const int MAX_EFFECTS = 10;

    /**
     * @throws \InvalidArgumentException when the label is blank or longer than the contract allows
     */
    public function __construct(
        public string $label,
    ) {
        if ('' === trim($label) || mb_strlen($label) > self::MAX_LABEL) {
            throw new \InvalidArgumentException(\sprintf('A push label is 1 to %d characters.', self::MAX_LABEL));
        }
    }
}
