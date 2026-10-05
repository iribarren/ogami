<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\JsonAccessDeniedHandler;
use App\Identity\Infrastructure\Security\JsonAuthenticationEntryPoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(JsonAuthenticationEntryPoint::class)]
#[CoversClass(JsonAccessDeniedHandler::class)]
final class JsonErrorResponsesTest extends TestCase
{
    #[Test]
    public function aMissingSessionIsAJson401(): void
    {
        $response = new JsonAuthenticationEntryPoint()->start(Request::create('/api/auth/me'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"Authentication required."}', $response->getContent());
    }

    #[Test]
    public function aMissingRoleIsAJson403(): void
    {
        $response = new JsonAccessDeniedHandler()->handle(Request::create('/api/studio'), new AccessDeniedException());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"error":"Access denied."}', $response->getContent());
    }
}
