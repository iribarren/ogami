<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Login with an unknown (or malformed) email still verifies the presented
 * password, against a dummy hash from the same hasher, so it takes as long as
 * a wrong password and the response time does not reveal which emails exist.
 *
 * Runs after the user loader is set (UserProviderListener, priority 1024) and
 * before the first listener that loads the user (UserCheckerListener, 256),
 * which would otherwise fail on the missing user without hashing anything.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 512, dispatcher: 'security.event_dispatcher.main')]
final readonly class UnknownUserPasswordCheckListener
{
    private const string DUMMY_HASH_KEY = 'identity.login.dummy_password_hash';

    public function __construct(
        private PasswordHasherFactoryInterface $hashers,
        private CacheInterface $cache,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(UserBadge::class) || !$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        try {
            $passport->getUser();
        } catch (UserNotFoundException $exception) {
            $credentials = $passport->getBadge(PasswordCredentials::class);
            \assert($credentials instanceof PasswordCredentials);

            $hasher = $this->hashers->getPasswordHasher(SecurityUser::class);
            $hasher->verify($this->dummyHash($hasher), $credentials->getPassword());

            throw $exception;
        }
    }

    /**
     * Hashing costs as much as verifying, so the dummy hash is made once and
     * cached; a deploy (cache clear) picks up a changed hasher configuration.
     */
    private function dummyHash(PasswordHasherInterface $hasher): string
    {
        return $this->cache->get(self::DUMMY_HASH_KEY, static fn (): string => $hasher->hash(bin2hex(random_bytes(16))));
    }
}
