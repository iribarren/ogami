<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a journal entry content to one JSON column (JSONB with the "jsonb" option, ADR 0007), in
 * the canonical shape of JournalEntryContent::toArray(). PostgreSQL does not keep the key order of
 * JSONB objects; JournalEntryContents::fromArray() reads fields by name and rebuilds the canonical
 * order.
 */
final class JournalEntryContentType extends JsonType
{
    public const string NAME = 'play_journal_entry_content';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof JournalEntryContent) {
            throw InvalidType::new($value, self::NAME, [JournalEntryContent::class, 'null']);
        }

        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?JournalEntryContent
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data)) {
            throw ValueNotConvertible::new($value, self::NAME, 'Stored journal entry content must be a JSON object.');
        }

        try {
            return JournalEntryContents::fromArray($data);
        } catch (InvalidJournalEntryContent $exception) {
            throw ValueNotConvertible::new($value, self::NAME, $exception->getMessage(), $exception);
        }
    }
}
