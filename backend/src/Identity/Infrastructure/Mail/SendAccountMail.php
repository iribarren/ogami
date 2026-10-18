<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use Symfony\Component\Mime\Email;

/**
 * Message on the command bus: send this account email. It is dispatched with a
 * DispatchAfterCurrentBusStamp, so it is handled once the command's transaction committed.
 */
final readonly class SendAccountMail
{
    public function __construct(
        public Email $email,
    ) {
    }
}
