<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\TokenGenerator;
use App\Identity\Domain\AccountToken;

/**
 * 32 random bytes (256 bits) as unpadded base64url, safe in a URL fragment.
 */
final readonly class RandomTokenGenerator implements TokenGenerator
{
    public function generate(): AccountToken
    {
        return AccountToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }
}
