<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use App\Identity\Application\InvitationAlreadyPending;
use App\Identity\Application\InvitationIdAlreadyInUse;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\InvitationStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    /** Name of the partial unique index on pending emails, see Invitation.orm.xml. */
    public const string PENDING_EMAIL_UNIQUE_CONSTRAINT = 'uniq_identity_invitation_pending_email';

    /** Name of the primary key (PostgreSQL default), so an id clash is told apart from the other unique indexes. */
    public const string PRIMARY_KEY_CONSTRAINT = 'identity_invitation_pkey';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside the
     * handler, instead of in the command bus transaction middleware.
     *
     * @throws InvitationAlreadyPending when another pending invitation already has this email
     * @throws InvitationIdAlreadyInUse when another invitation already has this id (a concurrent insert of the same id)
     */
    public function save(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::PENDING_EMAIL_UNIQUE_CONSTRAINT)) {
                throw InvitationAlreadyPending::for($invitation->email());
            }

            if (str_contains($exception->getMessage(), self::PRIMARY_KEY_CONSTRAINT)) {
                throw InvitationIdAlreadyInUse::for($invitation->id());
            }

            throw $exception;
        }
    }

    public function ofId(InvitationId $id): ?Invitation
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(Invitation::class, $id->toString());
    }

    public function ofIdForUpdate(InvitationId $id): ?Invitation
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->lockedOne('id', $id->toString());
    }

    public function pendingForEmail(Email $email): ?Invitation
    {
        // At most one since the partial unique index; the newest wins for rows stored before it.
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(
            ['email' => $email->toString(), 'status' => InvitationStatus::Pending],
            ['createdAt' => 'DESC'],
        );
    }

    public function pending(): array
    {
        return $this->entityManager->getRepository(Invitation::class)->findBy(
            ['status' => InvitationStatus::Pending],
            ['email' => 'ASC'],
        );
    }

    public function ofTokenHash(string $tokenHash): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function ofTokenHashForUpdate(string $tokenHash): ?Invitation
    {
        return $this->lockedOne('tokenHash', $tokenHash);
    }

    /**
     * SELECT … FOR UPDATE. HINT_REFRESH overwrites an invitation already in the identity map with
     * the locked row, so a state another transaction committed meanwhile is never read stale.
     */
    private function lockedOne(string $field, string $value): ?Invitation
    {
        /** @var list<Invitation> $found */
        $found = $this->entityManager
            ->createQuery('SELECT i FROM '.Invitation::class.' i WHERE i.'.$field.' = :value')
            ->setParameter('value', $value)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $found[0] ?? null;
    }
}
