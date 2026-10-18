<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepository
{
    /** Name of the unique index on email, see User.orm.xml. */
    public const string EMAIL_UNIQUE_CONSTRAINT = 'uniq_identity_user_email';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside
     * the handler, instead of in the command bus transaction middleware.
     *
     * @throws EmailAlreadyInUse when another user already has this email
     */
    public function save(User $user): void
    {
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::EMAIL_UNIQUE_CONSTRAINT)) {
                throw EmailAlreadyInUse::for($user->email());
            }

            throw $exception;
        }
    }

    public function ofId(UserId $id): ?User
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(User::class, $id->toString());
    }

    public function ofEmail(Email $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email->toString()]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(User::class)->findBy([], ['email' => 'ASC']);
    }

    /**
     * Locks every user row (the table is small and a json column cannot be filtered
     * portably) and keeps the active owners. HINT_REFRESH overwrites users already in
     * the identity map, such as the acting user that Security loaded, with the locked
     * rows; a user disabled meanwhile is refreshed too, so none stays stale.
     */
    public function activeOwnersForUpdate(): array
    {
        /** @var list<User> $users */
        $users = $this->entityManager
            ->createQuery('SELECT u FROM '.User::class.' u ORDER BY u.id')
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_values(array_filter($users, static fn (User $user): bool => !$user->isDisabled() && $user->hasRole(Role::Owner)));
    }
}
