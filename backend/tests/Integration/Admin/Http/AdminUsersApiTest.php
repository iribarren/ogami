<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin\Http;

use App\Admin\Infrastructure\Http\AdminController;
use App\Admin\Infrastructure\Http\AdminUserResponse;
use App\Admin\Infrastructure\Http\AdminUsersController;
use App\Tests\Support\Admin\AdminApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The owner manages users over the JSON API: list, roles, disable, enable, password reset (ADR 0034).
 */
#[CoversClass(AdminUsersController::class)]
#[CoversClass(AdminController::class)]
#[CoversClass(AdminUserResponse::class)]
final class AdminUsersApiTest extends AdminApiTestCase
{
    private const string USERS = '/api/admin/users';

    #[Test]
    public function theListHasEveryUserByEmailWithoutSecrets(): void
    {
        $ownerId = $this->signInAs(['OWNER', 'GAME_MANAGER'], 'owner@example.com');
        $adaId = $this->createUser('ada@example.com');

        $users = $this->users();

        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['ada@example.com', 'owner@example.com'], array_column($users, 'email'));
        self::assertSame(['createdAt', 'email', 'id', 'roles', 'status'], $this->sorted(array_keys($users[0])));
        self::assertSame([$adaId, ['SOLO_PLAYER'], 'active'], [$users[0]['id'], $users[0]['roles'], $users[0]['status']]);
        self::assertSame([$ownerId, ['OWNER', 'GAME_MANAGER']], [$users[1]['id'], $users[1]['roles']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', \is_string($users[0]['createdAt']) ? $users[0]['createdAt'] : '');
        self::assertStringNotContainsString('assword', $this->content());
    }

    #[Test]
    public function changingRolesAnswersTheUpdatedUser(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');

        $this->send('PUT', self::USERS."/$adaId/roles", ['roles' => ['GAME_MANAGER', 'SOLO_PLAYER']]);

        self::assertResponseStatusCodeSame(200);
        $user = $this->json();
        self::assertSame([$adaId, 'ada@example.com', ['GAME_MANAGER', 'SOLO_PLAYER'], 'active'], [$user['id'], $user['email'], $user['roles'], $user['status']]);
        self::assertSame([['GAME_MANAGER', 'SOLO_PLAYER']], array_column(array_filter($this->users(), static fn (array $u): bool => $u['id'] === $adaId), 'roles'));
    }

    #[Test]
    public function theOwnerCanKeepTheirOwnOwnerRoleButNotDropIt(): void
    {
        $ownerId = $this->signInAs();

        $this->send('PUT', self::USERS."/$ownerId/roles", ['roles' => ['OWNER', 'GAME_MANAGER']]);
        self::assertResponseStatusCodeSame(200);

        // A role change ends the session, as Symfony Security does: sign in again.
        $this->login('owner@example.com', self::PASSWORD);
        $this->send('PUT', self::USERS."/$ownerId/roles", ['roles' => ['GAME_MANAGER']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error'], array_keys($this->json()));
        self::assertSame(['OWNER', 'GAME_MANAGER'], $this->users()[0]['roles']);
    }

    /**
     * @param array<mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessableRoles')]
    public function noRoleOrAnUnknownRoleIsUnprocessable(array $body): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');

        $this->send('PUT', self::USERS."/$adaId/roles", $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error'], array_keys($this->json()));
        self::assertSame(['SOLO_PLAYER'], $this->users()[0]['roles']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unprocessableRoles(): iterable
    {
        yield 'empty list' => [['roles' => []]];
        yield 'unknown role' => [['roles' => ['WIZARD']]];
        yield 'unknown next to a known one' => [['roles' => ['OWNER', 'wizard']]];
        yield 'lower case' => [['roles' => ['owner']]];
    }

    #[Test]
    public function aBodyThatIsNotTheExpectedJsonFailsClosed(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');
        $path = self::USERS."/$adaId/roles";

        $this->client->request('PUT', $path, ['roles' => 'OWNER']);
        self::assertResponseStatusCodeSame(415);

        foreach (['{', '"OWNER"', '[]', '{}'] as $raw) {
            $this->client->request('PUT', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);
            self::assertResponseStatusCodeSame(400, $raw);
            self::assertStringStartsWith('{"error":"Send a JSON object', $this->content());
        }

        foreach (['OWNER', null, 7, ['a' => 'OWNER'], [['OWNER']], [null], [1]] as $roles) {
            $this->send('PUT', $path, ['roles' => $roles]);
            self::assertResponseStatusCodeSame(400, (string) json_encode($roles));
        }

        $this->send('PUT', $path, ['roles' => ['OWNER'], 'status' => 'disabled']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['SOLO_PLAYER'], $this->users()[0]['roles']);
    }

    #[Test]
    public function disablingEndsTheSessionAndEnablingRestoresSignIn(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');

        foreach (range(1, 2) as $unused) {
            $this->send('POST', self::USERS."/$adaId/disable");
            self::assertResponseStatusCodeSame(204);
            self::assertSame('', $this->content());
        }
        self::assertSame('disabled', $this->users()[0]['status']);

        $this->send('POST', self::USERS."/$adaId/enable");
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        self::assertSame('active', $this->users()[0]['status']);
    }

    #[Test]
    public function theOwnerCannotDisableTheirOwnAccount(): void
    {
        $ownerId = $this->signInAs();

        $this->send('POST', self::USERS."/$ownerId/disable");

        self::assertResponseStatusCodeSame(409);
        self::assertSame('active', $this->users()[0]['status']);
    }

    #[Test]
    public function anOwnerCanDisableAnotherOwner(): void
    {
        $this->signInAs();
        $otherId = $this->createUser('other@example.com', ['OWNER']);

        $this->send('POST', self::USERS."/$otherId/disable");

        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function userActions(): iterable
    {
        foreach (['disable', 'enable', 'password-reset'] as $action) {
            yield $action => ['POST', $action];
        }
        yield 'roles' => ['PUT', 'roles'];
    }

    #[Test]
    #[DataProvider('userActions')]
    public function anUnknownOrMalformedIdIsNotFound(string $method, string $action): void
    {
        $this->signInAs();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', '42'] as $id) {
            $this->send($method, self::USERS."/$id/$action", 'PUT' === $method ? ['roles' => ['OWNER']] : null);

            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame(['error'], array_keys($this->json()));
        }
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function aPasswordResetIsAcceptedAndMailedToTheUser(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');

        $this->send('POST', self::USERS."/$adaId/password-reset");

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->content());
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('ada@example.com', $this->mailer->sent[0]->getTo()[0]->getAddress());
        self::assertStringNotContainsString($this->lastToken(), $this->content());
    }

    #[Test]
    public function aPasswordResetForADisabledUserIsAConflict(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');
        $this->send('POST', self::USERS."/$adaId/disable");

        $this->send('POST', self::USERS."/$adaId/password-reset");

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function aMailFailureIs503AndTheResetStaysStored(): void
    {
        $this->signInAs();
        $adaId = $this->createUser('ada@example.com');
        $this->mailer->failure = new TransportException('smtp down');

        $this->send('POST', self::USERS."/$adaId/password-reset");

        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString('{"error":"The email could not be sent."}', $this->content());
        self::assertCount(1, self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM identity_password_reset'));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
