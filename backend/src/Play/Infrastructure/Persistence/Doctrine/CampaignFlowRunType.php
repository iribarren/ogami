<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunEvent;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDbalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's FlowRun to one JSON column (JSONB with the "jsonb" option, ADR 0007); null for
 * a campaign played freely. The FlowRun is always loaded and saved with its campaign.
 *
 * Stored shape: {status, phaseIndex, stage, sessionNumber, sceneNumber, sceneType, part, stepKey,
 * sequencePosition, scenesPlayed, answers: {stepKey: text}, forcedNextSceneType, phaseEnding,
 * switchCount, history: [{event, at, details: {…}}]}, every field present (null where the FlowRun
 * has none). The details are those of the event's FlowRunHistoryEntry factory. Times keep their
 * microseconds and UTC offset. Reading checks the shape and every enumerated value and fails on
 * anything else; FlowRun rules are not checked again (FlowRun::reconstitute()).
 *
 * Registered by its attribute (DoctrineBundle autoconfiguration), not in doctrine.yaml.
 */
#[AsDbalType(self::NAME)]
final class CampaignFlowRunType extends JsonType
{
    public const string NAME = 'play_campaign_flow_run';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const array PHASE_ENDINGS = ['endPhase', 'moveOn'];

    private const array PHASE_END_REASONS = ['finished', 'moveOn', 'endPhase'];

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof FlowRun) {
            throw InvalidType::new($value, self::NAME, [FlowRun::class, 'null']);
        }

        return parent::convertToDatabaseValue([
            'status' => $value->status()->value,
            'phaseIndex' => $value->phaseIndex(),
            'stage' => $value->stage()->value,
            'sessionNumber' => $value->scene()[0] ?? null,
            'sceneNumber' => $value->scene()[1] ?? null,
            'sceneType' => $value->sceneType(),
            'part' => $value->part()?->value,
            'stepKey' => $value->stepKey(),
            'sequencePosition' => $value->sequencePosition(),
            'scenesPlayed' => $value->scenesPlayed(),
            'answers' => (object) $value->answers(),
            'forcedNextSceneType' => $value->forcedNextSceneType(),
            'phaseEnding' => $value->phaseEndingReason(),
            'switchCount' => $value->switchCount(),
            'history' => array_map(
                static fn (FlowRunHistoryEntry $entry): array => [
                    'event' => $entry->event->value,
                    'at' => $entry->at->format(self::TIME_FORMAT),
                    'details' => (object) $entry->details,
                ],
                $value->history(),
            ),
        ], $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?FlowRun
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        return FlowRun::reconstitute(
            $this->enum(FlowRunStatus::class, $data, 'status') ?? throw $this->malformed('status', $this->oneOf(FlowRunStatus::cases())),
            $this->int($data, 'phaseIndex'),
            $this->enum(FlowRunStage::class, $data, 'stage') ?? throw $this->malformed('stage', $this->oneOf(FlowRunStage::cases())),
            $this->optionalInt($data, 'sessionNumber'),
            $this->optionalInt($data, 'sceneNumber'),
            $this->optionalString($data, 'sceneType'),
            $this->enum(ScenePart::class, $data, 'part'),
            $this->optionalString($data, 'stepKey'),
            $this->int($data, 'sequencePosition'),
            $this->int($data, 'scenesPlayed'),
            $this->answers($this->field($data, 'answers')),
            $this->optionalString($data, 'forcedNextSceneType'),
            $this->choice($data, 'phaseEnding', self::PHASE_ENDINGS, true),
            $this->int($data, 'switchCount'),
            array_map($this->historyEntry(...), $this->list($this->field($data, 'history'), 'history')),
        );
    }

    private function historyEntry(mixed $entry): FlowRunHistoryEntry
    {
        $event = $this->enum(FlowRunEvent::class, $entry, 'event') ?? throw $this->malformed('event', $this->oneOf(FlowRunEvent::cases()));
        $at = $this->time($entry, 'at');
        $details = $this->field($entry, 'details');

        return match ($event) {
            FlowRunEvent::Skip => FlowRunHistoryEntry::skip($at, $this->string($details, 'step'), $this->enum(ScenePart::class, $details, 'part') ?? throw $this->malformed('part', $this->oneOf(ScenePart::cases()))),
            FlowRunEvent::TrackerEdit => FlowRunHistoryEntry::trackerEdit($at, $this->string($details, 'tracker'), $this->int($details, 'from'), $this->int($details, 'to')),
            FlowRunEvent::SceneTypeSwitch => FlowRunHistoryEntry::sceneTypeSwitch($at, $this->int($details, 'scene'), $this->optionalString($details, 'from'), $this->string($details, 'to')),
            FlowRunEvent::Paused => FlowRunHistoryEntry::paused($at),
            FlowRunEvent::Resumed => FlowRunHistoryEntry::resumed($at),
            FlowRunEvent::SceneAbandoned => FlowRunHistoryEntry::sceneAbandoned($at, $this->int($details, 'session'), $this->int($details, 'scene')),
            FlowRunEvent::PhaseEnded => FlowRunHistoryEntry::phaseEnded($at, $this->string($details, 'phase'), (string) $this->choice($details, 'reason', self::PHASE_END_REASONS, false)),
            FlowRunEvent::Completed => FlowRunHistoryEntry::completed($at),
        };
    }

    /**
     * @return array<string, string>
     */
    private function answers(mixed $value): array
    {
        if (!\is_array($value)) {
            throw $this->malformed('answers', 'an object of strings');
        }

        $answers = [];
        foreach ($value as $stepKey => $text) {
            if (!\is_string($text)) {
                throw $this->malformed('answers', 'an object of strings');
            }

            $answers[(string) $stepKey] = $text;
        }

        return $answers;
    }

    private function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $field): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->malformed($field, 'a list');
        }

        return $value;
    }

    private function int(mixed $data, string $field): int
    {
        $value = $this->field($data, $field);
        if (!\is_int($value)) {
            throw $this->malformed($field, 'an integer');
        }

        return $value;
    }

    private function optionalInt(mixed $data, string $field): ?int
    {
        return null === $this->field($data, $field) ? null : $this->int($data, $field);
    }

    private function string(mixed $data, string $field): string
    {
        $value = $this->field($data, $field);
        if (!\is_string($value)) {
            throw $this->malformed($field, 'a string');
        }

        return $value;
    }

    private function optionalString(mixed $data, string $field): ?string
    {
        return null === $this->field($data, $field) ? null : $this->string($data, $field);
    }

    /**
     * @param list<string> $values
     */
    private function choice(mixed $data, string $field, array $values, bool $nullable): ?string
    {
        $value = $nullable ? $this->optionalString($data, $field) : $this->string($data, $field);
        if (null !== $value && !\in_array($value, $values, true)) {
            throw $this->malformed($field, $this->oneOf($values));
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return ?T null when the field is absent or null
     */
    private function enum(string $enum, mixed $data, string $field): ?\BackedEnum
    {
        $value = $this->optionalString($data, $field);

        return null === $value ? null : ($enum::tryFrom($value) ?? throw $this->malformed($field, $this->oneOf($enum::cases())));
    }

    /**
     * @param list<\BackedEnum|string> $values
     */
    private function oneOf(array $values): string
    {
        return 'one of '.implode(', ', array_map(static fn (\BackedEnum|string $value): string => '"'.($value instanceof \BackedEnum ? $value->value : $value).'"', $values));
    }

    private function time(mixed $data, string $field): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->string($data, $field));
        if (false === $time) {
            throw $this->malformed($field, 'a time like 2026-10-06T10:00:00.000000+00:00');
        }

        return $time;
    }

    private function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign FlowRun: "%s" must be %s.', $field, $expected));
    }
}
