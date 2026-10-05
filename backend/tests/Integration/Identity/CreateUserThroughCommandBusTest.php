<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\CreateUser;
use App\Identity\Application\CreateUserHandler;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use App\Shared\Application\Bus\CommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(CreateUserHandler::class)]
#[CoversClass(DoctrineUserRepository::class)]
final class CreateUserThroughCommandBusTest extends KernelTestCase
{
    #[Test]
    public function itCreatesAUserWithAVerifiableHash(): void
    {
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(
            new CreateUser('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'ada@example.com', 's3cret', ['GAME_MANAGER']),
        );

        $user = $container->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);
        self::assertNotSame('s3cret', $user->passwordHash());
        self::assertTrue(password_verify('s3cret', $user->passwordHash()));
    }

    /**
     * Two requests can both pass the handler's email check; the unique index
     * is the final guard, and its violation surfaces as EmailAlreadyInUse.
     */
    #[Test]
    public function aConcurrentDuplicateEmailIsReportedAsEmailAlreadyInUse(): void
    {
        $container = self::getContainer();
        $doctrine = $container->get(DoctrineUserRepository::class);
        $doctrine->save(User::register(
            UserId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'),
            Email::fromString('ada@example.com'),
            'hash',
            [Role::Owner],
        ));

        // A repository that misses the existing user, as a concurrent request would.
        $racing = new class($doctrine) implements UserRepository {
            public int $emailLookups = 0;

            public function __construct(private readonly UserRepository $inner)
            {
            }

            public function save(User $user): void
            {
                $this->inner->save($user);
            }

            public function ofId(UserId $id): ?User
            {
                return $this->inner->ofId($id);
            }

            public function ofEmail(Email $email): ?User
            {
                ++$this->emailLookups;

                return null;
            }
        };
        $container->set(CreateUserHandler::class, new CreateUserHandler($racing, $container->get(PasswordHasher::class)));

        try {
            $container->get(CommandBus::class)->dispatch(
                new CreateUser('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', 'ada@example.com', 'other', ['SOLO_PLAYER']),
            );
            self::fail('A second user with the same email was created.');
        } catch (EmailAlreadyInUse $exception) {
            self::assertStringContainsString('ada@example.com', $exception->getMessage());
        }

        // The handler's own check was bypassed, so the database index raised the error.
        self::assertSame(1, $racing->emailLookups);
    }
}
