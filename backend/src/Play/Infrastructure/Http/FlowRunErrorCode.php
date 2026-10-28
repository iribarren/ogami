<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

/**
 * The machine-readable reason a step of the FlowRun was refused for a rule of the sheet given the
 * Character's values (ADR 0045, ADR 0037). The values are the HTTP contract: the SPA branches on
 * them, so they never change with the wording of a message.
 */
enum FlowRunErrorCode: string
{
    case InsufficientFunds = 'insufficient_funds';
}
