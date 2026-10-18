<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Bus;

use App\Shared\Application\Bus\Command;
use App\Shared\Infrastructure\Bus\MessengerCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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

        $bus = new MessengerCommandBus($this->throwing(new HandlerFailedException(Envelope::wrap($command), [$failure])), new NullLogger());

        $this->expectExceptionObject($failure);

        $bus->dispatch($command);
    }

    #[Test]
    public function aFailingAfterCommitHandlerSurfacesItsOwnException(): void
    {
        $failure = new \RuntimeException('mail down');
        $command = new class implements Command {};
        $handlerFailure = new HandlerFailedException(Envelope::wrap(new \stdClass()), [$failure]);

        $bus = new MessengerCommandBus($this->throwing(new DelayedMessageHandlingException([$handlerFailure], Envelope::wrap($command))), new NullLogger());

        $this->expectExceptionObject($failure);

        $bus->dispatch($command);
    }

    #[Test]
    public function everyAfterCommitFailureButTheFirstIsLogged(): void
    {
        $first = new \RuntimeException('mail down');
        $second = new \RuntimeException('second mail down');
        $command = new class implements Command {};
        $delayed = new DelayedMessageHandlingException([
            new HandlerFailedException(Envelope::wrap(new \stdClass()), [$first]),
            new HandlerFailedException(Envelope::wrap(new \stdClass()), [$second]),
        ], Envelope::wrap($command));
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'context' => $context];
            }
        };

        try {
            new MessengerCommandBus($this->throwing($delayed), $logger)->dispatch($command);
            self::fail('The first failure should surface.');
        } catch (\RuntimeException $surfaced) {
            self::assertSame($first, $surfaced);
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame($second, $logger->records[0]['context']['exception']);
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
