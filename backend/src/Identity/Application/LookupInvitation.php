<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks what a pending, unexpired invitation link is for.
 *
 * @implements Query<InvitationLookupView>
 */
final readonly class LookupInvitation implements Query
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
