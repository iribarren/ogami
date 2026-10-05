<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * What a likelihood oracle answers: yes or no, possibly exceptional ("yes, and…", "no, and…").
 */
enum YesNoAnswer: string
{
    case ExceptionalYes = 'exceptional_yes';
    case Yes = 'yes';
    case No = 'no';
    case ExceptionalNo = 'exceptional_no';

    public function isYes(): bool
    {
        return self::ExceptionalYes === $this || self::Yes === $this;
    }
}
