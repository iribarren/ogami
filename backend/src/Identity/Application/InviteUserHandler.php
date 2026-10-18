<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class InviteUserHandler implements CommandHandler
{
    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private TokenGenerator $tokens,
        private AccountLinks $links,
        private AccountMailer $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * An expired pending invitation is revoked and saved before the new one, because only
     * one invitation per email can be pending. The mail is sent last and, being queued until
     * the command commits, never leaves for a refused invitation.
     *
     * @throws UnknownRole
     * @throws InvitationMustHaveARole
     * @throws InvitationIdAlreadyInUse
     * @throws EmailAlreadyInUse
     * @throws InvitationAlreadyPending also when a concurrent request wins the race (the repository translates the unique index violation)
     * @throws MailUnavailable
     */
    public function __invoke(InviteUser $command): void
    {
        $id = InvitationId::fromString($command->invitationId);
        $email = Email::fromString($command->email);
        $invitedBy = UserId::fromString($command->invitedBy);
        $roles = array_map(
            static fn (string $value): Role => Role::tryFrom($value) ?? throw UnknownRole::named($value),
            $command->roles,
        );
        if ([] === $roles) {
            throw InvitationMustHaveARole::none();
        }

        if ($this->invitations->ofId($id) instanceof Invitation) {
            throw InvitationIdAlreadyInUse::for($id);
        }

        if ($this->users->ofEmail($email) instanceof User) {
            throw EmailAlreadyInUse::for($email);
        }

        $now = $this->clock->now();
        $pending = $this->invitations->pendingForEmail($email);
        if ($pending instanceof Invitation) {
            if (!$pending->isExpiredAt($now)) {
                throw InvitationAlreadyPending::for($email);
            }

            $pending->revoke();
            $this->invitations->save($pending);
        }

        $token = $this->tokens->generate();
        $invitation = Invitation::invite($id, $email, $roles, $token, $invitedBy, $now);
        $this->invitations->save($invitation);

        $this->mailer->sendInvitation($email, $this->links->invitation($token), $invitation->roles(), $invitation->expiresAt());
    }
}
