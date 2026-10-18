<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvitationRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RevokeInvitationHandler implements CommandHandler
{
    public function __construct(
        private InvitationRepository $invitations,
        private InvitationLoader $loader,
    ) {
    }

    /**
     * @throws InvitationNotFound
     */
    public function __invoke(RevokeInvitation $command): void
    {
        $invitation = $this->loader->pending($command->invitationId);

        $invitation->revoke();
        $this->invitations->save($invitation);
    }
}
