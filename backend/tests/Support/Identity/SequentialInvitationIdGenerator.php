<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\InvitationIdGenerator;
use App\Identity\Domain\InvitationId;

/**
 * Test double that hands out "invitation-1", "invitation-2"… in order.
 */
final class SequentialInvitationIdGenerator implements InvitationIdGenerator
{
    private int $next = 1;

    public function generate(): InvitationId
    {
        return InvitationId::fromString(\sprintf('invitation-%d', $this->next++));
    }
}
