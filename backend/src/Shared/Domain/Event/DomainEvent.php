<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Something that happened in a domain model and that other parts of the
 * system may react to. Dispatched in-process (ADR 0002).
 */
interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
}
