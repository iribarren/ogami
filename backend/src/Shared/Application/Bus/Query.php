<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * A request to read state, answered by exactly one QueryHandler.
 *
 * @template-covariant TResult The type the handler returns
 */
interface Query
{
}
