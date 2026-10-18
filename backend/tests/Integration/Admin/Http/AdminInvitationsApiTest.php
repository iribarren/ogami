<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin\Http;

use App\Admin\Infrastructure\Http\AdminInvitationResponse;
use App\Admin\Infrastructure\Http\AdminInvitationsController;
use App\Tests\Support\Admin\AdminApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The owner invites by email over the JSON API: list, invite, resend, revoke (ADR 0034).
 */
#[CoversClass(AdminInvitationsController::class)]
#[CoversClass(AdminInvitationResponse::class)]
final class AdminInvitationsApiTest extends AdminApiTestCase
{
    private const string INVITATIONS = '/api/admin/invitations';

    #[Test]
    public function inviteAnswers201WithTheInvitationAndMailsTheLink(): void
    {
        $ownerId = $this->signInAs();

        $this->send('POST', self::INVITATIONS, ['email' => ' Ada@Example.com ', 'roles' => ['GAME_MANAGER', 'SOLO_PLAYER']]);

        self::assertResponseStatusCodeSame(201);
        $invitation = $this->json();
        self::assertSame(['createdAt', 'email', 'expired', 'expiresAt', 'id', 'roles'], $this->sorted(array_keys($invitation)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->field('id'));
        self::assertSame(['ada@example.com', ['GAME_MANAGER', 'SOLO_PLAYER'], false], [$invitation['email'], $invitation['roles'], $invitation['expired']]);
        self::assertGreaterThan(new \DateTimeImmutable($this->field('createdAt')), new \DateTimeImmutable($this->field('expiresAt')));
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('ada@example.com', $this->mailer->sent[0]->getTo()[0]->getAddress());
        self::assertStringNotContainsString($this->lastToken(), $this->content());
        self::assertSame($ownerId, self::getContainer()->get(Connection::class)->fetchOne('SELECT invited_by FROM identity_invitation WHERE id = ?', [$invitation['id']]));
    }

    #[Test]
    public function theListHasThePendingInvitationsByEmailWithAnExpiredFlag(): void
    {
        $this->signInAs();
        $this->send('GET', self::INVITATIONS);
        self::assertSame([], $this->json());

        foreach (['zoe@example.com', 'ada@example.com'] as $email) {
            $this->send('POST', self::INVITATIONS, ['email' => $email, 'roles' => ['SOLO_PLAYER']]);
        }
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE identity_invitation SET expires_at = '2020-01-01T00:00:00+00:00' WHERE email = 'zoe@example.com'");

        $this->send('GET', self::INVITATIONS);

        self::assertResponseStatusCodeSame(200);
        $list = $this->json();
        self::assertSame(['ada@example.com', 'zoe@example.com'], array_column($list, 'email'));
        self::assertSame([false, true], array_column($list, 'expired'));
        self::assertStringNotContainsString($this->lastToken(), $this->content());
    }

    #[Test]
    public function anEmailWithAnAccountOrAPendingInvitationIsAConflict(): void
    {
        $this->signInAs();
        $this->createUser('ada@example.com');

        $this->send('POST', self::INVITATIONS, ['email' => 'ADA@example.com', 'roles' => ['SOLO_PLAYER']]);
        self::assertResponseStatusCodeSame(409);

        $this->send('POST', self::INVITATIONS, ['email' => 'bob@example.com', 'roles' => ['SOLO_PLAYER']]);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', self::INVITATIONS, ['email' => 'bob@example.com', 'roles' => ['OWNER']]);
        self::assertResponseStatusCodeSame(409);

        self::assertCount(1, $this->mailer->sent);
    }

    /**
     * @param array<mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessableInvitations')]
    public function aMalformedEmailNoRoleOrAnUnknownRoleIsUnprocessable(array $body): void
    {
        $this->signInAs();

        $this->send('POST', self::INVITATIONS, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error'], array_keys($this->json()));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unprocessableInvitations(): iterable
    {
        yield 'malformed email' => [['email' => 'not an email', 'roles' => ['SOLO_PLAYER']]];
        yield 'blank email' => [['email' => ' ', 'roles' => ['SOLO_PLAYER']]];
        yield 'no role' => [['email' => 'ada@example.com', 'roles' => []]];
        yield 'unknown role' => [['email' => 'ada@example.com', 'roles' => ['WIZARD']]];
    }

    #[Test]
    public function aBodyThatIsNotTheExpectedJsonFailsClosed(): void
    {
        $this->signInAs();

        $this->client->request('POST', self::INVITATIONS, ['email' => 'ada@example.com', 'roles' => ['OWNER']]);
        self::assertResponseStatusCodeSame(415);

        foreach (['{', '"ada@example.com"', '[]', '{"email":"ada@example.com"}', '{"roles":["OWNER"]}'] as $raw) {
            $this->client->request('POST', self::INVITATIONS, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);
            self::assertResponseStatusCodeSame(400, $raw);
            self::assertStringStartsWith('{"error":"Send a JSON object', $this->content());
        }

        foreach ([['email' => 42, 'roles' => ['OWNER']], ['email' => null, 'roles' => ['OWNER']], ['email' => ['a'], 'roles' => ['OWNER']], ['email' => 'ada@example.com', 'roles' => 'OWNER'], ['email' => 'ada@example.com', 'roles' => [1]], ['email' => 'ada@example.com', 'roles' => ['OWNER'], 'invitedBy' => 'x']] as $body) {
            $this->send('POST', self::INVITATIONS, $body);
            self::assertResponseStatusCodeSame(400, (string) json_encode($body));
        }
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function aMailFailureOnInviteIs503AndTheInvitationStays(): void
    {
        $this->signInAs();
        $this->mailer->failure = new TransportException('smtp down');

        $this->send('POST', self::INVITATIONS, ['email' => 'ada@example.com', 'roles' => ['SOLO_PLAYER']]);

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString('{"error":"The email could not be sent."}', $this->content());
        $this->mailer->failure = null;
        $this->send('GET', self::INVITATIONS);
        self::assertSame(['ada@example.com'], array_column($this->json(), 'email'));
    }

    #[Test]
    public function resendingAnswers202WithANewLinkAndRenewsTheExpiry(): void
    {
        $this->signInAs();
        $this->send('POST', self::INVITATIONS, ['email' => 'ada@example.com', 'roles' => ['SOLO_PLAYER']]);
        $id = $this->field('id');
        $firstToken = $this->lastToken();
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE identity_invitation SET expires_at = '2020-01-01T00:00:00+00:00'");

        $this->send('POST', self::INVITATIONS."/$id/resend");

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->content());
        self::assertCount(2, $this->mailer->sent);
        self::assertNotSame($firstToken, $this->lastToken());
        $this->send('GET', self::INVITATIONS);
        self::assertFalse($this->invitations()[0]['expired']);
    }

    #[Test]
    public function aMailFailureOnResendIs503(): void
    {
        $this->signInAs();
        $this->send('POST', self::INVITATIONS, ['email' => 'ada@example.com', 'roles' => ['SOLO_PLAYER']]);
        $id = $this->field('id');
        $this->mailer->failure = new TransportException('smtp down');

        $this->send('POST', self::INVITATIONS."/$id/resend");

        self::assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function revokingAnswers204AndTheInvitationIsGone(): void
    {
        $this->signInAs();
        $this->send('POST', self::INVITATIONS, ['email' => 'ada@example.com', 'roles' => ['SOLO_PLAYER']]);
        $id = $this->field('id');

        $this->send('DELETE', self::INVITATIONS."/$id");

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        $this->send('GET', self::INVITATIONS);
        self::assertSame([], $this->json());

        $this->send('DELETE', self::INVITATIONS."/$id");
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', self::INVITATIONS."/$id/resend");
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invitationActions(): iterable
    {
        yield 'resend' => ['POST', '/resend'];
        yield 'revoke' => ['DELETE', ''];
    }

    #[Test]
    #[DataProvider('invitationActions')]
    public function anUnknownOrMalformedIdIsNotFound(string $method, string $suffix): void
    {
        $this->signInAs();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', '42'] as $id) {
            $this->send($method, self::INVITATIONS."/$id$suffix");

            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame(['error'], array_keys($this->json()));
        }
        self::assertSame([], $this->mailer->sent);
    }
}
