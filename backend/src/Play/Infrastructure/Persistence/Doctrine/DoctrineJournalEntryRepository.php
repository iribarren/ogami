<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryNotTheLatest;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RandomEventAlreadyRolled;
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

    /**
     * Name of the partial unique index on the question an oracle table entry answers
     * (`content->>'forEntry'`), created by a migration: Doctrine cannot map an expression index.
     */
    public const string RANDOM_EVENT_UNIQUE_INDEX = 'uniq_play_journal_entry_random_event';

    /** An entry of the campaign of entry e recorded after it, in latestOfCampaign() order. */
    private const string LATER_ENTRY = 'SELECT 1 FROM play_journal_entry later WHERE later.campaign_id = e.campaign_id AND (later.recorded_at, later.id) > (e.recorded_at, e.id)';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a primary key violation surfaces here, inside the handler. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws JournalEntryAlreadyExists when the id is taken, also by a concurrent record
     * @throws RandomEventAlreadyRolled  when the question's random event is already rolled, also by a concurrent roll
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

            $content = $entry->content();
            if (str_contains($exception->getMessage(), self::RANDOM_EVENT_UNIQUE_INDEX) && $content instanceof OracleTableContent && null !== $content->forEntry()) {
                throw RandomEventAlreadyRolled::forEntry($content->forEntry());
            }

            throw $exception;
        }
    }

    /**
     * Updates the stored content in one conditional statement: it matches only while the stored
     * check has no push part (absent or a JSON null, which reads the same) and, with $asLatest, while
     * no entry of the campaign comes after it in recording order (recordedAt, then id, as
     * latestOfCampaign()), so a concurrent push or record that got there first makes this one fail
     * instead of overwriting it. Entries are loaded read-only objects (the content is immutable), so
     * Doctrine cannot flush this change itself: the loaded copy is detached and the next read loads
     * the update. It runs in the command bus transaction, which rolls back on the exception.
     *
     * @throws CheckAlreadyPushed       when the stored entry was pushed already, also by a concurrent push
     * @throws JournalEntryNotTheLatest with $asLatest, when the campaign has a later entry, also one a concurrent request recorded
     */
    public function savePush(JournalEntry $pushed, bool $asLatest = false): void
    {
        $loaded = $this->ofId($pushed->id()) ?? throw new \LogicException(\sprintf('Journal entry "%s" is not kept.', $pushed->id()->toString()));
        $updated = $this->entityManager->getConnection()->executeStatement(
            "UPDATE play_journal_entry e SET content = CAST(:content AS jsonb) WHERE e.id = :id AND COALESCE(e.content->'pool'->'push', 'null'::jsonb) = 'null'::jsonb"
            .($asLatest ? ' AND NOT EXISTS ('.self::LATER_ENTRY.')' : ''),
            ['content' => json_encode($pushed->content()->toArray(), \JSON_THROW_ON_ERROR), 'id' => $pushed->id()->toString()],
        );
        $this->entityManager->detach($loaded);

        if (1 === $updated) {
            return;
        }

        if ($asLatest && false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM play_journal_entry e WHERE e.id = :id AND EXISTS ('.self::LATER_ENTRY.')', ['id' => $pushed->id()->toString()])) {
            throw JournalEntryNotTheLatest::entry($pushed->id());
        }

        throw CheckAlreadyPushed::entry($pushed->id());
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

    public function latestOfCampaign(CampaignId $campaignId): ?JournalEntry
    {
        if (!Uuid::isValid($campaignId->toString())) {
            return null;
        }

        $entry = $this->entityManager->createQuery(
            'SELECT e FROM '.JournalEntry::class.' e WHERE e.campaignId = :campaignId ORDER BY e.recordedAt DESC, e.id DESC',
        )->setParameter('campaignId', $campaignId->toString())->setMaxResults(1)->getOneOrNullResult();
        \assert(null === $entry || $entry instanceof JournalEntry);

        return $entry;
    }
}
