<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Infrastructure\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Infrastructure\Http\GameSystemSheetController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The race the API tests cannot stage: the draft is deleted between a committed write and the read
 * of the sheet that answers it. Stub buses: the command succeeds, the query finds no draft.
 */
#[CoversClass(GameSystemSheetController::class)]
final class GameSystemSheetControllerTest extends TestCase
{
    #[Test]
    public function aDraftGoneAfterTheWriteIsAConflictNotNotFound(): void
    {
        $controller = new GameSystemSheetController(
            new class implements CommandBus {
                public function dispatch(Command $command): void
                {
                }
            },
            new class implements QueryBus {
                public function ask(Query $query): never
                {
                    throw GameSystemNotFound::withKey('mine');
                }
            },
        );
        $user = new class implements AuthenticatedUser {
            public function id(): string
            {
                return 'user-1';
            }
        };
        $json = ['CONTENT_TYPE' => 'application/json'];

        $responses = [
            $controller->create('mine', new Request(server: $json, content: '{"expectedVersion":1,"field":{"key":"luck"}}'), $user),
            $controller->replace('mine', 'luck', new Request(server: $json, content: '{"expectedVersion":1,"field":{"key":"luck"}}'), $user),
            $controller->delete('mine', 'luck', new Request(['expectedVersion' => '1']), $user),
            $controller->move('mine', 'luck', new Request(server: $json, content: '{"expectedVersion":1,"index":0}'), $user),
        ];

        foreach ($responses as $response) {
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('reload it', (string) $response->getContent());
        }
    }
}
