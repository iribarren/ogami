<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Mail\SendAccountMail;
use App\Identity\Infrastructure\Mail\SymfonyAccountMailer;
use App\Tests\Support\Identity\SpyMessageBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Address;

#[CoversClass(SymfonyAccountMailer::class)]
#[CoversClass(SendAccountMail::class)]
final class SymfonyAccountMailerTest extends TestCase
{
    private const string LINK = 'https://ogami.example/accept-invitation#token=abc_DEF-123';

    #[Test]
    public function anInvitationNamesTheRolesTheLinkAndTheExpiry(): void
    {
        [$mailer, $bus] = $this->mailer();

        $mailer->sendInvitation(
            Email::fromString('Ada@Example.com'),
            self::LINK,
            [Role::GameManager, Role::SoloPlayer],
            new \DateTimeImmutable('2026-10-25T11:30:00+02:00'),
        );

        $message = $bus->onlyMail();
        $email = $message->email;
        self::assertSame(['ada@example.com'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('ogami@example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('You are invited to Ogami', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString(self::LINK, $text);
        self::assertStringContainsString('GAME_MANAGER, SOLO_PLAYER', $text);
        self::assertStringContainsString('25 October 2026, 09:30 UTC', $text);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('href="'.self::LINK.'"', $html);
        self::assertStringContainsString('25 October 2026, 09:30 UTC', $html);
    }

    #[Test]
    public function aPasswordResetCarriesTheLinkAndTheExpiry(): void
    {
        [$mailer, $bus] = $this->mailer();

        $mailer->sendPasswordReset(
            Email::fromString('ada@example.com'),
            'https://ogami.example/reset-password#token=xyz',
            new \DateTimeImmutable('2026-10-18T10:00:00+00:00'),
        );

        $email = $bus->onlyMail()->email;
        self::assertSame('Reset your Ogami password', $email->getSubject());
        self::assertStringContainsString('https://ogami.example/reset-password#token=xyz', (string) $email->getTextBody());
        self::assertStringContainsString('18 October 2026, 10:00 UTC', (string) $email->getHtmlBody());
    }

    #[Test]
    public function theMailIsDispatchedOnlyAfterTheCurrentCommandIsHandled(): void
    {
        [$mailer, $bus] = $this->mailer();

        $mailer->sendPasswordReset(Email::fromString('ada@example.com'), 'https://x/reset-password#token=t', new \DateTimeImmutable());

        self::assertNotNull($bus->envelopes[0]->last(DispatchAfterCurrentBusStamp::class));
    }

    /**
     * @return array{SymfonyAccountMailer, SpyMessageBus}
     */
    private function mailer(): array
    {
        $bus = new SpyMessageBus();

        return [new SymfonyAccountMailer($bus, 'ogami@example.test'), $bus];
    }
}
