<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Keys the login throttle on the email as the Email value object normalizes
 * it (trimmed, lower case). Symfony's default limiter only lowercases, so
 * " ada@example.com" would otherwise get a fresh allowance for the same user.
 */
#[AsDecorator('security.login_throttling.main.limiter')]
final readonly class NormalizedLoginRateLimiter implements PeekableRequestRateLimiterInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PeekableRequestRateLimiterInterface $inner,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        return $this->inner->consume($this->normalized($request));
    }

    public function peek(Request $request): RateLimit
    {
        return $this->inner->peek($this->normalized($request));
    }

    public function reset(Request $request): void
    {
        $this->inner->reset($this->normalized($request));
    }

    private function normalized(Request $request): Request
    {
        $username = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);
        if (!\is_string($username)) {
            return $request;
        }

        $normalized = $request->duplicate();
        $normalized->attributes->set(SecurityRequestAttributes::LAST_USERNAME, strtolower(trim($username)));

        return $normalized;
    }
}
