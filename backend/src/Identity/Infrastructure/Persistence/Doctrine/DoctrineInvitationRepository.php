<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\InvitationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
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
        return $this->entityManager->getRepository(Invitation::class)->findOneBy([
            'email' => $email->toString(),
            'status' => InvitationStatus::Pending,
        ]);
    }

    public function ofTokenHash(string $tokenHash): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }
}
