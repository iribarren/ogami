<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\MailUnavailable;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SendAccountMailHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    /**
     * @throws MailUnavailable
     */
    public function __invoke(SendAccountMail $message): void
    {
        try {
            $this->mailer->send($message->email);
        } catch (TransportExceptionInterface $exception) {
            throw MailUnavailable::because($exception);
        }
    }
}
