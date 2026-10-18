<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Http;

use App\Identity\Application\InviteUser;
use App\Identity\Infrastructure\Http\InvitationLookupResponse;
use App\Identity\Infrastructure\Http\PublicAccountController;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Identity\PublicAccountApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Looking up and accepting an invitation, anonymously over the JSON API (ADR 0034).
 */
#[CoversClass(PublicAccountController::class)]
#[CoversClass(InvitationLookupResponse::class)]
final class InvitationApiTest extends PublicAccountApiTestCase
{
    private const string LOOKUP = '/api/auth/invitations/lookup';
    private const string ACCEPT = '/api/auth/invitations/accept';
    private const string INVITATION_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string INVALID_LINK = '{"error":"This link is invalid or has expired."}';

    #[Test]
    public function aLookupWithoutASessionReadsTheEmailRolesAndExpiry(): void
    {
        $this->invite('ada@example.com');

        $this->post(self::LOOKUP, ['token' => $this->lastToken()]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $lookup = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($lookup);
        self::assertSame(['email', 'expiresAt', 'roles'], $this->sorted(array_keys($lookup)));
        self::assertSame('ada@example.com', $lookup['email']);
        self::assertSame(['GAME_MANAGER', 'SOLO_PLAYER'], $lookup['roles']);
        self::assertIsString($lookup['expiresAt']);
        self::assertSame($lookup['expiresAt'], new \DateTimeImmutable($lookup['expiresAt'])->format(\DATE_RFC3339));
    }

    #[Test]
    public function aLookupOfAnUnknownBlankOrAcceptedLinkIsUnprocessable(): void
    {
        $this->invite('ada@example.com');
        $token = $this->lastToken();
        $this->post(self::ACCEPT, ['token' => $token, 'password' => 'correct horse']);

        foreach (['unknown-token', '', $token] as $linkToken) {
            $this->post(self::LOOKUP, ['token' => $linkToken]);

            self::assertResponseStatusCodeSame(422);
            self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
        }
    }

    #[Test]
    public function acceptingCreatesTheAccountWithoutSigningInAndOnlyOnce(): void
    {
        $this->invite('ada@example.com');
        $token = $this->lastToken();

        $this->post(self::ACCEPT, ['token' => $token, 'password' => 'correct horse']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        self::assertCount(0, $this->client->getResponse()->headers->getCookies());
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
        $this->login('ada@example.com', 'correct horse');
        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString(
            \sprintf('{"id":"%s","email":"ada@example.com","roles":["GAME_MANAGER","SOLO_PLAYER"]}', $this->userIdOf('ada@example.com')),
            $this->content(),
        );

        $this->post(self::ACCEPT, ['token' => $token, 'password' => 'another horse']);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
    }

    #[Test]
    public function aWeakPasswordIsUnprocessableAndTheInvitationStaysUsable(): void
    {
        $this->invite('ada@example.com');
        $token = $this->lastToken();

        $this->post(self::ACCEPT, ['token' => $token, 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"The password must have between 8 and 4096 characters."}', $this->content());
        $this->post(self::ACCEPT, ['token' => $token, 'password' => 'correct horse']);
        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function anUnknownBlankOrExpiredLinkCannotBeAccepted(): void
    {
        $this->invite('ada@example.com');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE identity_invitation SET expires_at = '2020-01-01T00:00:00+00:00'");

        foreach (['unknown-token', '', $this->lastToken()] as $token) {
            $this->post(self::ACCEPT, ['token' => $token, 'password' => 'correct horse']);

            self::assertResponseStatusCodeSame(422);
            self::assertJsonStringEqualsJsonString(self::INVALID_LINK, $this->content());
        }
    }

    #[Test]
    public function anEmailThatGotAnAccountMeanwhileIsAConflict(): void
    {
        $this->invite('ada@example.com');
        $this->createUser('ada@example.com');

        $this->post(self::ACCEPT, ['token' => $this->lastToken(), 'password' => 'correct horse']);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString('{"error":"A user with email \"ada@example.com\" already exists."}', $this->content());
    }

    private function invite(string $email): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new InviteUser(self::INVITATION_ID, $email, ['GAME_MANAGER', 'SOLO_PLAYER'], self::OWNER_ID));
    }

    private function userIdOf(string $email): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM identity_user WHERE email = ?', [$email]);

        return \is_string($id) ? $id : self::fail('No user stored.');
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
