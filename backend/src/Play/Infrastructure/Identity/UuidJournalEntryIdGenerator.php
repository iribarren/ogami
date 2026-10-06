<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Domain\Journal\JournalEntryId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidJournalEntryIdGenerator implements JournalEntryIdGenerator
{
    public function generate(): JournalEntryId
    {
        return JournalEntryId::fromString(Uuid::v7()->toRfc4122());
    }
}
