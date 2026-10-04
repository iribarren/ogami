<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marker for command handlers. Implementations expose
 * `__invoke(SomeCommand $command): void` and are registered on the command bus
 * automatically (see config/services.yaml).
 */
interface CommandHandler
{
}
