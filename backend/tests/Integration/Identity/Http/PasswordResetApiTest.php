<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Http;

use App\Identity\Application\DisableUser;
use App\Identity\Infrastructure\Http\PublicAccountController;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Identity\PublicAccountApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Asking for a password reset link and using it, anonymously over the JSON API (ADR 0034).
 */
#[CoversClass(PublicAccountController::class)]
final class PasswordResetApiTest extends PublicAccountApiTestCase
{
    private const string REQUESTS = '/api/auth/password-reset-requests';
    private const string RESETS = '/api/auth/password-resets';
    private const string INVALID_LINK = '{"error":"This link is invalid or has expired."}';

    #[Test]
    public function aKnownEmailGetsAnEmptyAcceptedAnswerAndALinkWithoutASession(): void
    {
        $this->createUser('ada@example.com');

        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->content());
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('ada@example.com', $this->mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function unknownDisabledAndKnownEmailsGetTheSameAnswer(): void
    {
        $this->createUser('ada@example.com');
        $bobId = $this->createUser('bob@example.com');
        self::getContainer()->get(CommandBus::class)->dispatch(new DisableUser($this->createUser('owner@example.com', ['OWNER']), $bobId));

        $answers = [];
        foreach (['ada@example.com', 'nobody@example.com', 'bob@example.com'] as $email) {
            $this->post(self::REQUESTS, ['email' => $email]);
            $answers[] = [$this->client->getResponse()->getStatusCode(), $this->content(), $this->client->getResponse()->headers->get('Content-Type')];
        }

        self::assertSame([$answers[0], $answers[0], $answers[0]], $answers);
        self::assertSame(202, $answers[0][0]);
        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function aMalformedEmailIsUnprocessable(): void
    {
        $this->post(self::REQUESTS, ['email' => 'not an email']);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"\"not an email\" is not a valid email address."}', $this->content());
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function aFourthRequestForTheSameEmailWithinTheWindowSendsNothingButStillAnswers202(): void
    {
        $this->createUser('ada@example.com');

        // Case and surrounding spaces do not open a new allowance.
        foreach (['ada@example.com', 'ADA@example.com', ' ada@example.com ', 'Ada@Example.com'] as $email) {
            $this->post(self::REQUESTS, ['email' => $email]);
            self::assertResponseStatusCodeSame(202);
            self::assertSame('', $this->content());
        }

        self::assertCount(3, $this->mailer->sent);
    }

    #[Test]
    public function aMalformedEmailIsLimitedToo(): void
    {
        foreach (['bad', 'bad', 'bad', 'bad'] as $email) {
            $this->post(self::REQUESTS, ['email' => $email]);
        }

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->content());
    }

    #[Test]
    public function anEleventhRequestFromOneIpSendsNothingAndAnotherIpIsNotAffected(): void
    {
        $this->createUser('ada@example.com');
        for ($i = 1; $i <= 10; ++$i) {
            $this->post(self::REQUESTS, ['email' => \sprintf('nobody%d@example.com', $i)], '203.0.113.9');
        }

        $this->post(self::REQUESTS, ['email' => 'ada@example.com'], '203.0.113.9');
        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->mailer->sent);

        $this->post(self::REQUESTS, ['email' => 'ada@example.com'], '203.0.113.10');
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function aMailFailureStillAnswers202AndKeepsTheReset(): void
    {
        $this->createUser('ada@example.com');
        $this->mailer->failure = new TransportException('smtp down');

        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->content());
        self::assertCount(1, self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM identity_password_reset'));
    }

    #[Test]
    public function followingTheLinkChangesThePasswordOnce(): void
    {
        $this->createUser('ada@example.com');
        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);
        $token = $this->lastToken();

        $this->post(self::RESETS, ['token' => $token, 'password' => 'a brand new password']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        $this->login('ada@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        $this->login('ada@example.com', 'a brand new password');
        self::assertResponseStatusCodeSame(200);

        $this->post(self::RESETS, ['token' => $token, 'password' => 'yet another password']);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
    }

    #[Test]
    public function anUnknownBlankOrExpiredTokenIsUnprocessable(): void
    {
        $this->createUser('ada@example.com');
        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE identity_password_reset SET expires_at = '2020-01-01T00:00:00+00:00'");
        $expired = $this->lastToken();

        foreach (['unknown-token', '', $expired] as $token) {
            $this->post(self::RESETS, ['token' => $token, 'password' => 'a brand new password']);

            self::assertResponseStatusCodeSame(422);
            self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
        }
    }

    #[Test]
    public function aWeakPasswordIsUnprocessableAndTheLinkStaysUsable(): void
    {
        $this->createUser('ada@example.com');
        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);
        $token = $this->lastToken();

        $this->post(self::RESETS, ['token' => $token, 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"The password must have between 8 and 4096 characters."}', $this->content());
        $this->post(self::RESETS, ['token' => $token, 'password' => 'a long enough password']);
        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function aLinkOfAUserDisabledMeanwhileIsUnprocessable(): void
    {
        $id = $this->createUser('ada@example.com');
        $this->post(self::REQUESTS, ['email' => 'ada@example.com']);
        $token = $this->lastToken();
        self::getContainer()->get(CommandBus::class)->dispatch(new DisableUser($this->createUser('owner@example.com', ['OWNER']), $id));

        $this->post(self::RESETS, ['token' => $token, 'password' => 'a brand new password']);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
    }
}
