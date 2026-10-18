<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use App\Identity\Application\PasswordResetIdAlreadyInUse;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\UserId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePasswordResetRepository implements PasswordResetRepository
{
    /** Name of the primary key (PostgreSQL default), so an id clash is told apart from the token hash index. */
    public const string PRIMARY_KEY_CONSTRAINT = 'identity_password_reset_pkey';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique violation surfaces here, inside the handler,
     * instead of in the command bus transaction middleware.
     *
     * @throws PasswordResetIdAlreadyInUse when another reset already has this id (a concurrent insert of the same id)
     */
    public function save(PasswordReset $reset): void
    {
        $this->entityManager->persist($reset);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::PRIMARY_KEY_CONSTRAINT)) {
                throw PasswordResetIdAlreadyInUse::for($reset->id());
            }

            throw $exception;
        }
    }

    public function ofId(PasswordResetId $id): ?PasswordReset
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(PasswordReset::class, $id->toString());
    }

    /**
     * SELECT … FOR UPDATE. HINT_REFRESH overwrites a reset already in the identity map with the
     * locked row, so a use another transaction committed meanwhile is never read stale.
     */
    public function ofTokenHashForUpdate(string $tokenHash): ?PasswordReset
    {
        /** @var list<PasswordReset> $found */
        $found = $this->entityManager
            ->createQuery('SELECT r FROM '.PasswordReset::class.' r WHERE r.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $found[0] ?? null;
    }

    /**
     * Removes through the unit of work, so no deleted reset stays in the identity map.
     */
    public function deleteForUserExcept(UserId $userId, PasswordResetId $keep): void
    {
        /** @var list<PasswordReset> $others */
        $others = $this->entityManager->getRepository(PasswordReset::class)->findBy(['userId' => $userId->toString()]);
        foreach ($others as $other) {
            if (!$other->id()->equals($keep)) {
                $this->entityManager->remove($other);
            }
        }

        $this->entityManager->flush();
    }
}
