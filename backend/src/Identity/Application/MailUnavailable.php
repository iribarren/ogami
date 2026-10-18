<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The mail transport refused or could not deliver an account email. The invitation
 * or reset it belongs to stays stored.
 */
final class MailUnavailable extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('The email could not be sent.', 0, $previous);
    }
}
