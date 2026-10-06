<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Journal entries in play_journal_entry (Journal.JournalEntry.orm.xml). recordedAt keeps its
 * microseconds and UTC offset; entries recorded at the same time keep their order through their
 * time-ordered ids (UUID v7).
 */
final readonly class DoctrineJournalEntryRepository implements JournalEntryRepository
{
    /** PostgreSQL's name for the primary key of play_journal_entry. */
    public const string PRIMARY_KEY_CONSTRAINT = 'play_journal_entry_pkey';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a primary key violation surfaces here, inside the handler. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws JournalEntryAlreadyExists when the id is taken, also by a concurrent record
     */
    public function add(JournalEntry $entry): void
    {
        if ($this->ofId($entry->id()) instanceof JournalEntry) {
            throw JournalEntryAlreadyExists::withId($entry->id());
        }

        $this->entityManager->persist($entry);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::PRIMARY_KEY_CONSTRAINT)) {
                throw JournalEntryAlreadyExists::withId($entry->id());
            }

            throw $exception;
        }
    }

    public function ofId(JournalEntryId $id): ?JournalEntry
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(JournalEntry::class, $id->toString());
    }

    public function ofCampaign(CampaignId $campaignId): array
    {
        if (!Uuid::isValid($campaignId->toString())) {
            return [];
        }

        /** @var list<JournalEntry> $entries */
        $entries = $this->entityManager->createQuery(
            'SELECT e FROM '.JournalEntry::class.' e WHERE e.campaignId = :campaignId ORDER BY e.recordedAt ASC, e.id ASC',
        )->setParameter('campaignId', $campaignId->toString())->getResult();

        return $entries;
    }
}
