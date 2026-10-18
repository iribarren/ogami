<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\Command;
use App\Shared\Infrastructure\Bus\MessengerCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(MessengerCommandBus::class)]
final class MessengerCommandBusTest extends TestCase
{
    #[Test]
    public function aFailingHandlerSurfacesItsOwnException(): void
    {
        $failure = new \DomainException('refused');
        $command = new class implements Command {};

        $bus = new MessengerCommandBus($this->throwing(new HandlerFailedException(Envelope::wrap($command), [$failure])));

        $this->expectExceptionObject($failure);

        $bus->dispatch($command);
    }

    #[Test]
    public function aFailingAfterCommitHandlerSurfacesItsOwnException(): void
    {
        $failure = new \RuntimeException('mail down');
        $command = new class implements Command {};
        $handlerFailure = new HandlerFailedException(Envelope::wrap(new \stdClass()), [$failure]);

        $bus = new MessengerCommandBus($this->throwing(new DelayedMessageHandlingException([$handlerFailure], Envelope::wrap($command))));

        $this->expectExceptionObject($failure);

        $bus->dispatch($command);
    }

    private function throwing(\Throwable $exception): MessageBusInterface
    {
        return new readonly class($exception) implements MessageBusInterface {
            public function __construct(private \Throwable $exception)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->exception;
            }
        };
    }
}
