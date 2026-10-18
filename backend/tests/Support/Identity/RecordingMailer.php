<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Keeps the emails handed to the transport, and what a probe saw at that moment.
 * With a failure it refuses every email, like a transport that is down.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<int> */
    public array $probes = [];

    /**
     * @param (\Closure(): int)|null $probe read at every send, e.g. a row count
     */
    public function __construct(
        private readonly ?\Closure $probe = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        \assert($message instanceof Email);
        $this->sent[] = $message;
        $this->probes[] = $this->probe instanceof \Closure ? ($this->probe)() : 0;
    }
}
