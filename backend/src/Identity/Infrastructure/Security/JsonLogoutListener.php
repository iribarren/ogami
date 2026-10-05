<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * `POST /api/auth/logout` answers 204 instead of Symfony's default redirect.
 * Runs after the default listener (priority 64) so its response wins.
 */
#[AsEventListener(event: LogoutEvent::class, dispatcher: 'security.event_dispatcher.main')]
final readonly class JsonLogoutListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT));
    }
}
