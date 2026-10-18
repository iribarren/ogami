<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin\Http;

use App\Admin\Infrastructure\Http\AdminInvitationsController;
use App\Admin\Infrastructure\Http\AdminUsersController;
use App\Tests\Support\Admin\AdminApiTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every Admin route is for the owner only: 401 without a session, 403 for any other role (ADR 0006, ADR 0034).
 */
#[CoversClass(AdminUsersController::class)]
#[CoversClass(AdminInvitationsController::class)]
final class AdminAccessApiTest extends AdminApiTestCase
{
    /**
     * @return iterable<string, array{string, string, array<mixed>|null}>
     */
    public static function routes(): iterable
    {
        $id = self::UNKNOWN_ID;

        yield 'list users' => ['GET', '/api/admin/users', null];
        yield 'change roles' => ['PUT', "/api/admin/users/$id/roles", ['roles' => ['OWNER']]];
        yield 'disable' => ['POST', "/api/admin/users/$id/disable", null];
        yield 'enable' => ['POST', "/api/admin/users/$id/enable", null];
        yield 'send password reset' => ['POST', "/api/admin/users/$id/password-reset", null];
        yield 'list invitations' => ['GET', '/api/admin/invitations', null];
        yield 'invite' => ['POST', '/api/admin/invitations', ['email' => 'ada@example.com', 'roles' => ['OWNER']]];
        yield 'resend' => ['POST', "/api/admin/invitations/$id/resend", null];
        yield 'revoke' => ['DELETE', "/api/admin/invitations/$id", null];
    }

    /**
     * @param array<mixed>|null $body
     */
    #[Test]
    #[DataProvider('routes')]
    public function withoutASessionTheAnswerIs401(string $method, string $path, ?array $body): void
    {
        $this->send($method, $path, $body);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<mixed>|null $body
     */
    #[Test]
    #[DataProvider('routes')]
    public function anyRoleButOwnerGets403(string $method, string $path, ?array $body): void
    {
        foreach ([['SOLO_PLAYER'], ['GAME_MANAGER'], ['SOLO_PLAYER', 'GAME_MANAGER']] as $n => $roles) {
            $this->signInAs($roles, \sprintf('user%d@example.com', $n));
            $this->send($method, $path, $body);

            self::assertResponseStatusCodeSame(403, implode(',', $roles));
            self::assertJsonStringEqualsJsonString('{"error":"Access denied."}', $this->content());
        }
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function aPlayerCannotMakeThemselvesOwner(): void
    {
        $id = $this->signInAs(['SOLO_PLAYER'], 'ada@example.com');

        $this->send('PUT', "/api/admin/users/$id/roles", ['roles' => ['OWNER']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/auth/me');
        self::assertSame(['SOLO_PLAYER'], $this->json()['roles']);
    }

    #[Test]
    public function exactlyTheAdminPathsNeedTheOwnerRole(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');

        foreach (['/api/admin', '/api/admin/users', '/api/admin/invitations/x/resend'] as $path) {
            self::assertSame([['ROLE_OWNER'], null], $accessMap->getPatterns(Request::create($path)), $path);
        }
        self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create('/api/administrators')));
    }
}
