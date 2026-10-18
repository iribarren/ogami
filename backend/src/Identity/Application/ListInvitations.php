<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the pending invitations, ordered by email.
 *
 * @implements Query<list<InvitationView>>
 */
final readonly class ListInvitations implements Query
{
}
