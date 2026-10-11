<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's Threads to one JSON column (JSONB with the "jsonb" option). They are immutable
 * values identified by their id within the campaign, always loaded and saved with it (ADR 0007).
 *
 * Stored shape: [{id, name, tags, freeTags, weight, status}], in creation order; `status` is
 * "open" or "closed". The column defaults to an empty list, which is what a campaign stored before
 * Threads reads as. Reading checks the shape and the rules Thread::of() holds (not whether the Tags
 * are in the release, which only the pinned release can tell); stored data that breaks either
 * fails closed with a clear error instead of loading a campaign in a state the domain refuses.
 */
final class CampaignThreadsType extends JsonType
{
    public const string NAME = 'play_campaign_threads';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $items = [];
        foreach ($value as $thread) {
            if (!$thread instanceof Thread) {
                throw InvalidType::new($thread, self::NAME, [Thread::class]);
            }

            $items[] = [
                'id' => $thread->id()->toString(),
                'name' => $thread->name(),
                'tags' => $thread->tags(),
                'freeTags' => $thread->freeTags(),
                'weight' => $thread->weight(),
                'status' => $thread->status()->value,
            ];
        }

        return parent::convertToDatabaseValue($items, $platform);
    }

    /**
     * @return list<Thread>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->malformed('threads', 'a list');
        }

        return array_map($this->thread(...), $data);
    }

    private function thread(mixed $data): Thread
    {
        $status = $this->string($data, 'status');

        try {
            return Thread::of(
                ThreadId::fromString($this->string($data, 'id')),
                $this->string($data, 'name'),
                $this->strings($data, 'tags'),
                $this->strings($data, 'freeTags'),
                $this->int($data, 'weight'),
                ItemStatus::tryFrom($status) ?? throw $this->malformed('status', 'one of "open", "closed"'),
            );
        } catch (InvalidThread $exception) {
            throw ValueNotConvertible::new(Thread::class, self::NAME, 'Stored campaign threads: '.$exception->getMessage());
        }
    }

    private function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $data, string $field): array
    {
        $value = $this->field($data, $field);
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->malformed($field, 'a list of strings');
        }

        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw $this->malformed($field, 'a list of strings');
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private function int(mixed $data, string $field): int
    {
        $value = $this->field($data, $field);
        if (!\is_int($value)) {
            throw $this->malformed($field, 'an integer');
        }

        return $value;
    }

    private function string(mixed $data, string $field): string
    {
        $value = $this->field($data, $field);
        if (!\is_string($value)) {
            throw $this->malformed($field, 'a string');
        }

        return $value;
    }

    private function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign threads: "%s" must be %s.', $field, $expected));
    }
}
