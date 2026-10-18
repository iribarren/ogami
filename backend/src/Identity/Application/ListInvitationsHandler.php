<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ListInvitationsHandler implements QueryHandler
{
    public function __construct(
        private InvitationRepository $invitations,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<InvitationView>
     */
    public function __invoke(ListInvitations $query): array
    {
        $now = $this->clock->now();

        return array_map(
            static fn (Invitation $invitation): InvitationView => InvitationView::fromInvitation($invitation, $now),
            $this->invitations->pending(),
        );
    }
}
