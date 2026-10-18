<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvitationRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class ResendInvitationHandler implements CommandHandler
{
    public function __construct(
        private InvitationRepository $invitations,
        private InvitationLoader $loader,
        private TokenGenerator $tokens,
        private AccountLinks $links,
        private AccountMailer $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * An expired pending invitation can be resent: that is how the owner renews it.
     *
     * @throws InvitationNotFound
     * @throws MailUnavailable
     */
    public function __invoke(ResendInvitation $command): void
    {
        $invitation = $this->loader->pending($command->invitationId);
        $token = $this->tokens->generate();

        $invitation->renew($token, $this->clock->now());
        $this->invitations->save($invitation);

        $this->mailer->sendInvitation($invitation->email(), $this->links->invitation($token), $invitation->roles(), $invitation->expiresAt());
    }
}
