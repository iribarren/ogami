<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Infrastructure\Mail\SendAccountMail;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps what was dispatched instead of handling it.
 */
final class SpyMessageBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return $this->envelopes[] = Envelope::wrap($message, $stamps);
    }

    /**
     * The one account email dispatched.
     */
    public function onlyMail(): SendAccountMail
    {
        Assert::assertCount(1, $this->envelopes);
        $message = $this->envelopes[0]->getMessage();
        Assert::assertInstanceOf(SendAccountMail::class, $message);

        return $message;
    }
}
