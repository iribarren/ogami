<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Campaigns in play_campaign, sessions and scenes included (Campaign.Campaign.orm.xml). Every time
 * keeps its microseconds and UTC offset.
 */
final readonly class DoctrineCampaignRepository implements CampaignRepository
{
    /** PostgreSQL's name for the primary key of play_campaign. */
    public const string PRIMARY_KEY_CONSTRAINT = 'play_campaign_pkey';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a primary key violation surfaces here, inside the handler. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws CampaignAlreadyExists when the id is taken, also by a concurrent create
     */
    public function add(Campaign $campaign): void
    {
        if ($this->ofId($campaign->id()) instanceof Campaign) {
            throw CampaignAlreadyExists::withId($campaign->id());
        }

        $this->entityManager->persist($campaign);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::PRIMARY_KEY_CONSTRAINT)) {
                throw CampaignAlreadyExists::withId($campaign->id());
            }

            throw $exception;
        }
    }

    /**
     * Doctrine tracks the campaigns it loaded, so saving flushes their changes. Flushing writes the
     * whole unit of work: add() and save() also keep unsaved changes of other loaded campaigns,
     * which the port allows (CampaignRepository).
     *
     * The version column is an optimistic lock: the update only matches the version this copy was
     * loaded with, so a save in between (another request) makes it fail instead of being lost. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws CampaignModifiedConcurrently when another request saved the campaign since it was loaded
     * @throws \LogicException              when the campaign was not added or loaded through this repository
     */
    public function save(Campaign $campaign): void
    {
        if (!$this->entityManager->contains($campaign)) {
            throw new \LogicException(\sprintf('Campaign "%s" was not added or loaded through this repository.', $campaign->id()->toString()));
        }

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            if ($exception->getEntity() !== $campaign) {
                throw $exception;
            }

            throw CampaignModifiedConcurrently::withId($campaign->id());
        }
    }

    public function ofId(CampaignId $id): ?Campaign
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(Campaign::class, $id->toString());
    }

    public function ownedBy(string $ownerId): array
    {
        if (!Uuid::isValid($ownerId)) {
            return [];
        }

        /** @var list<Campaign> $campaigns */
        $campaigns = $this->entityManager->createQuery(
            'SELECT c FROM '.Campaign::class.' c WHERE c.ownerId = :ownerId ORDER BY c.createdAt DESC, c.id DESC',
        )->setParameter('ownerId', $ownerId)->getResult();

        return $campaigns;
    }
}
