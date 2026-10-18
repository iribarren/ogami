<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountToken;

/**
 * Builds the links in account emails. The token goes in the URL fragment, which
 * browsers never send to a server or write to its logs, so it is never in a query string.
 */
final readonly class AccountLinks
{
    /**
     * @param string $publicUrl where the SPA is served, e.g. "http://localhost:8080"
     */
    public function __construct(
        private string $publicUrl,
    ) {
    }

    public function invitation(AccountToken $token): string
    {
        return $this->link('/accept-invitation', $token);
    }

    public function passwordReset(AccountToken $token): string
    {
        return $this->link('/reset-password', $token);
    }

    private function link(string $path, AccountToken $token): string
    {
        return rtrim($this->publicUrl, '/').$path.'#token='.$token->value();
    }
}
