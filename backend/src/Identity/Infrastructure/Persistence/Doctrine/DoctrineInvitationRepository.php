<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use App\Identity\Application\InvitationAlreadyPending;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\InvitationStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    /** Name of the partial unique index on pending emails, see Invitation.orm.xml. */
    public const string PENDING_EMAIL_UNIQUE_CONSTRAINT = 'uniq_identity_invitation_pending_email';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside the
     * handler, instead of in the command bus transaction middleware.
     *
     * @throws InvitationAlreadyPending when another pending invitation already has this email
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
}
