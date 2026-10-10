<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps the snapshot of the Flow step that recorded a journal entry to one JSON column (JSONB with
 * the "jsonb" option, ADR 0007), in the shape of FlowStepSnapshot::toArray(): {key, title, prompt}.
 * Null for an entry the player recorded freely, entries stored before Flow steps included.
 *
 * Registered by its attribute (DoctrineBundle autoconfiguration), not in doctrine.yaml.
 */
#[AsDbalType(self::NAME)]
final class JournalEntryFlowStepType extends JsonType
{
    public const string NAME = 'play_journal_entry_flow_step';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof FlowStepSnapshot) {
            throw InvalidType::new($value, self::NAME, [FlowStepSnapshot::class, 'null']);
        }

        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?FlowStepSnapshot
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data)) {
            throw ValueNotConvertible::new($value, self::NAME, 'A stored Flow step must be a JSON object.');
        }

        try {
            return FlowStepSnapshot::fromArray($data);
        } catch (InvalidJournalEntryContent $exception) {
            throw ValueNotConvertible::new($value, self::NAME, $exception->getMessage(), $exception);
        }
    }
}
