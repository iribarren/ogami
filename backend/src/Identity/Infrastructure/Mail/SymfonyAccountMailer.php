<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\AccountMailer;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Email as Mime;

/**
 * Builds the account emails (plain text plus simple HTML) and queues them on the
 * command bus until the running command has committed.
 */
final readonly class SymfonyAccountMailer implements AccountMailer
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $bus,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function sendInvitation(Email $email, string $link, array $roles, \DateTimeImmutable $expiresAt): void
    {
        $roleList = implode(', ', array_map(static fn (Role $role): string => $role->value, $roles));
        $expires = $this->formatExpiry($expiresAt);

        $this->queue(
            $email,
            'You are invited to Ogami',
            \sprintf("You have been invited to Ogami with the roles: %s.\n\nAccept the invitation and choose your password:\n%s\n\nThe link expires on %s.", $roleList, $link, $expires),
            \sprintf(
                '<p>You have been invited to Ogami with the roles: <strong>%s</strong>.</p><p><a href="%s">Accept the invitation</a> and choose your password.</p><p>The link expires on %s.</p>',
                $this->escape($roleList),
                $this->escape($link),
                $this->escape($expires),
            ),
        );
    }

    public function sendPasswordReset(Email $email, string $link, \DateTimeImmutable $expiresAt): void
    {
        $expires = $this->formatExpiry($expiresAt);

        $this->queue(
            $email,
            'Reset your Ogami password',
            \sprintf("A password reset was requested for your Ogami account.\n\nChoose a new password:\n%s\n\nThe link expires on %s. If you did not ask for it, ignore this email.", $link, $expires),
            \sprintf(
                '<p>A password reset was requested for your Ogami account.</p><p><a href="%s">Choose a new password</a>.</p><p>The link expires on %s. If you did not ask for it, ignore this email.</p>',
                $this->escape($link),
                $this->escape($expires),
            ),
        );
    }

    private function queue(Email $to, string $subject, string $text, string $html): void
    {
        $mime = new Mime()
            ->from($this->from)
            ->to($to->toString())
            ->subject($subject)
            ->text($text)
            ->html($html);

        $this->bus->dispatch(new SendAccountMail($mime), [new DispatchAfterCurrentBusStamp()]);
    }

    private function formatExpiry(\DateTimeImmutable $expiresAt): string
    {
        return $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('j F Y, H:i').' UTC';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
