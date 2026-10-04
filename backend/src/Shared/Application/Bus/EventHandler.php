<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marker for domain event handlers. Implementations expose
 * `__invoke(SomeDomainEvent $event): void` and are registered on the event bus
 * automatically (see config/services.yaml). An event may have zero or many handlers.
 */
interface EventHandler
{
}
