<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBus
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(Command $command): void
    {
        try {
            $this->bus->dispatch($command);
        } catch (DelayedMessageHandlingException $exception) {
            // Handlers of messages dispatched after the current command (after commit) failed.
            // The first failure surfaces; the others are logged so none disappears. Only the
            // exception is logged: never a message body, which can hold a token or a link.
            $failures = array_map($this->unwrap(...), $exception->getWrappedExceptions());
            $first = array_shift($failures) ?? $exception;
            foreach ($failures as $failure) {
                $this->logger->error('Another after-commit handler failed.', ['exception' => $failure]);
            }

            throw $first;
        } catch (HandlerFailedException $exception) {
            throw $exception->getPrevious() ?? $exception;
        }
    }

    private function unwrap(\Throwable $exception): \Throwable
    {
        return $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
    }
}
