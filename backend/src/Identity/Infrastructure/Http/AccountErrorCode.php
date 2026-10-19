<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

/**
 * The machine-readable reason of an error from the public account endpoints. The values are the
 * HTTP contract: the SPA branches on them, so they never change with the wording of a message.
 */
enum AccountErrorCode: string
{
    case InvalidAccountLink = 'invalid_account_link';
    case WeakPassword = 'weak_password';
    case InvalidEmail = 'invalid_email';
    case EmailAlreadyInUse = 'email_already_in_use';
    case MalformedRequest = 'malformed_request';
    case NotJson = 'not_json';
}
