<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunEvent;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\ShopStock;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\Flow\ItemKind;
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
 * switchCount, hook, phaseOpened, sessionOpeningDue, picked: {kind, ids} or null, autoPickDue, history: [{event, at, details: {…}}],
 * pendingCheck: {stepKey, entryId, characterId, difficulty, bandKey, checkKey, special} or null}, every
 * field present (null where the FlowRun has none). The details are those of the event's
 * FlowRunHistoryEntry factory (a skip's part is a scene part or a hook). Times keep their
 * microseconds and UTC offset. Reading checks the shape and every enumerated value and fails on
 * anything else, a hook without stage "hook" or the other way round included; other FlowRun rules
 * are not checked again (FlowRun::reconstitute()). Rows stored before hooks ran (play-flow-run
 * slice 20) lack hook, phaseOpened and sessionOpeningDue: they read as no hook, the current phase's
 * opening already run and no session opening due. Rows stored before the pick step (play-threads-npcs
 * slice 7) lack picked: they read as nothing picked. Picked items need a Scene (they end with it), at
 * most 10 distinct ids, and a kind of NPC or Thread. Rows stored before the scene pick waited after a
 * scene (preset-mythic-flow slice 3, ADR 0020) lack autoPickDue: they read as waiting (false), so no
 * scene starts by surprise. A pending check (ADR 0041) is the current step's while guidance is active,
 * else the row fails to load; rows stored before check step rolls could pend (rules-dice-pools slice
 * 5) lack pendingCheck: they read as none. A pending roll of a single-check step keeps no checkKey
 * (null), and rows stored before check sets (ADR 0042) lack it: both read the step's own check.
 *
 * Registered by its attribute (DoctrineBundle autoconfiguration), not in doctrine.yaml.
 */
#[AsDbalType(self::NAME)]
final class CampaignFlowRunType extends JsonType
{
    public const string NAME = 'play_campaign_flow_run';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const array PHASE_ENDINGS = ['endPhase', 'moveOn', 'finished'];

    private const array PHASE_END_REASONS = ['finished', 'moveOn', 'endPhase'];

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof FlowRun) {
            throw InvalidType::new($value, self::NAME, [FlowRun::class, 'null']);
        }

        $pending = $value->pendingCheck();
        $stock = $value->shopStock();

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
            'hook' => $value->hook()?->value,
            'phaseOpened' => $value->phaseOpened(),
            'sessionOpeningDue' => $value->sessionOpeningDue(),
            'picked' => $value->picked() instanceof PickedItems ? ['kind' => $value->picked()->kind->value, 'ids' => $value->picked()->ids] : null,
            'autoPickDue' => $value->autoPickDue(),
            'history' => array_map(
                static fn (FlowRunHistoryEntry $entry): array => [
                    'event' => $entry->event->value,
                    'at' => $entry->at->format(self::TIME_FORMAT),
                    'details' => (object) $entry->details,
                ],
                $value->history(),
            ),
            'pendingCheck' => $pending instanceof PendingCheck ? [
                'stepKey' => $pending->stepKey,
                'entryId' => $pending->entryId,
                'characterId' => $pending->characterId,
                'difficulty' => $pending->difficulty,
                'bandKey' => $pending->bandKey,
                'checkKey' => $pending->checkKey,
                'special' => $pending->special?->value,
            ] : null,
            'shopStock' => $stock instanceof ShopStock ? ['stepKey' => $stock->stepKey, 'gear' => $stock->gear] : null,
        ], $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?FlowRun
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        $stage = $this->enum(FlowRunStage::class, $data, 'stage') ?? throw $this->malformed('stage', $this->oneOf(FlowRunStage::cases()));
        $hook = $this->enum(Hook::class, $data, 'hook');
        if ((FlowRunStage::Hook === $stage) !== $hook instanceof Hook) {
            throw $this->malformed('hook', FlowRunStage::Hook === $stage ? 'set at stage "hook"' : 'null outside stage "hook"');
        }

        $sceneNumber = $this->optionalInt($data, 'sceneNumber');
        $picked = $this->picked($data);
        if ($picked instanceof PickedItems && null === $sceneNumber) {
            throw $this->malformed('picked', 'null outside a Scene');
        }

        $status = $this->enum(FlowRunStatus::class, $data, 'status') ?? throw $this->malformed('status', $this->oneOf(FlowRunStatus::cases()));
        $stepKey = $this->optionalString($data, 'stepKey');
        $pending = $this->pendingCheck($data);
        if ($pending instanceof PendingCheck && (FlowRunStatus::Active !== $status || $pending->stepKey !== $stepKey)) {
            throw $this->malformed('pendingCheck', "the current step's, while guidance is active");
        }

        $stock = $this->shopStock($data);
        if ($stock instanceof ShopStock && $stock->stepKey !== $stepKey) {
            throw $this->malformed('shopStock', "the current step's");
        }

        return FlowRun::reconstitute(
            $status,
            $this->int($data, 'phaseIndex'),
            $stage,
            $this->optionalInt($data, 'sessionNumber'),
            $sceneNumber,
            $this->optionalString($data, 'sceneType'),
            $this->enum(ScenePart::class, $data, 'part'),
            $stepKey,
            $this->int($data, 'sequencePosition'),
            $this->int($data, 'scenesPlayed'),
            $this->answers($this->field($data, 'answers')),
            $this->optionalString($data, 'forcedNextSceneType'),
            $this->choice($data, 'phaseEnding', self::PHASE_ENDINGS, true),
            $this->int($data, 'switchCount'),
            array_map($this->historyEntry(...), $this->list($this->field($data, 'history'), 'history')),
            $hook,
            $this->bool($data, 'phaseOpened', true),
            $this->bool($data, 'sessionOpeningDue', false),
            $picked,
            $this->bool($data, 'autoPickDue', false),
            $pending,
            $stock,
        );
    }

    /**
     * @return ?PendingCheck null for a row stored before check step rolls could pend, or without one
     */
    private function pendingCheck(mixed $data): ?PendingCheck
    {
        $pending = $this->field($data, 'pendingCheck');
        if (null === $pending) {
            return null;
        }

        if (!\is_array($pending) || array_is_list($pending)) {
            throw $this->malformed('pendingCheck', 'an object or null');
        }

        try {
            return new PendingCheck($this->string($pending, 'stepKey'), $this->string($pending, 'entryId'), $this->string($pending, 'characterId'), $this->optionalInt($pending, 'difficulty'), $this->string($pending, 'bandKey'), $this->optionalString($pending, 'checkKey'), $this->enum(SpecialResult::class, $pending, 'special'));
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new('pendingCheck', self::NAME, \sprintf('Stored campaign FlowRun: "pendingCheck" is invalid: %s', $e->getMessage()));
        }
    }

    /**
     * @return ?ShopStock null for a row stored before shop steps (ADR 0045), or without a stock
     */
    private function shopStock(mixed $data): ?ShopStock
    {
        $stock = $this->field($data, 'shopStock');
        if (null === $stock) {
            return null;
        }

        if (!\is_array($stock) || array_is_list($stock)) {
            throw $this->malformed('shopStock', 'an object or null');
        }

        $gear = array_map(
            fn (mixed $key): string => \is_string($key) ? $key : throw $this->malformed('shopStock', 'a list of gear keys'),
            $this->list($this->field($stock, 'gear'), 'shopStock'),
        );

        try {
            return new ShopStock($this->string($stock, 'stepKey'), $gear);
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new('shopStock', self::NAME, \sprintf('Stored campaign FlowRun: "shopStock" is invalid: %s', $e->getMessage()));
        }
    }

    /**
     * @return ?PickedItems null for a row stored before the pick step, or without picked items
     */
    private function picked(mixed $data): ?PickedItems
    {
        $picked = $this->field($data, 'picked');
        if (null === $picked) {
            return null;
        }

        $kind = $this->enum(ItemKind::class, $picked, 'kind') ?? throw $this->malformed('kind', $this->oneOf(ItemKind::cases()));
        $ids = array_map(
            fn (mixed $id): string => \is_string($id) ? $id : throw $this->malformed('picked', 'a list of id strings'),
            $this->list($this->field($picked, 'ids'), 'picked'),
        );

        try {
            return new PickedItems($kind, $ids);
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new('picked', self::NAME, \sprintf('Stored campaign FlowRun: "picked" is invalid: %s', $e->getMessage()));
        }
    }

    private function historyEntry(mixed $entry): FlowRunHistoryEntry
    {
        $event = $this->enum(FlowRunEvent::class, $entry, 'event') ?? throw $this->malformed('event', $this->oneOf(FlowRunEvent::cases()));
        $at = $this->time($entry, 'at');
        $details = $this->field($entry, 'details');

        return match ($event) {
            FlowRunEvent::Skip => FlowRunHistoryEntry::skip($at, $this->string($details, 'step'), $this->skippedIn($details)),
            FlowRunEvent::TrackerEdit => FlowRunHistoryEntry::trackerEdit($at, $this->string($details, 'tracker'), $this->int($details, 'from'), $this->int($details, 'to')),
            FlowRunEvent::SceneTypeSwitch => FlowRunHistoryEntry::sceneTypeSwitch($at, $this->int($details, 'scene'), $this->optionalString($details, 'from'), $this->string($details, 'to')),
            FlowRunEvent::SceneTypeSwitchIgnored => FlowRunHistoryEntry::sceneTypeSwitchIgnored($at, $this->optionalInt($details, 'scene'), $this->string($details, 'sceneType')),
            FlowRunEvent::Paused => FlowRunHistoryEntry::paused($at),
            FlowRunEvent::Resumed => FlowRunHistoryEntry::resumed($at),
            FlowRunEvent::SceneAbandoned => FlowRunHistoryEntry::sceneAbandoned($at, $this->int($details, 'session'), $this->int($details, 'scene')),
            FlowRunEvent::PhaseEnded => FlowRunHistoryEntry::phaseEnded($at, $this->string($details, 'phase'), (string) $this->choice($details, 'reason', self::PHASE_END_REASONS, false)),
            FlowRunEvent::Completed => FlowRunHistoryEntry::completed($at),
        };
    }

    /**
     * A skip's part: a scene part, or the hook the step was in.
     */
    private function skippedIn(mixed $details): ScenePart|Hook
    {
        $part = $this->string($details, 'part');

        return ScenePart::tryFrom($part) ?? Hook::tryFrom($part) ?? throw $this->malformed('part', $this->oneOf([...ScenePart::cases(), ...Hook::cases()]));
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

    /**
     * @param bool $legacy the value of a row stored before the field existed (the field is absent)
     */
    private function bool(mixed $data, string $field, bool $legacy): bool
    {
        if (!\is_array($data) || !\array_key_exists($field, $data)) {
            return $legacy;
        }

        if (!\is_bool($data[$field])) {
            throw $this->malformed($field, 'a boolean');
        }

        return $data[$field];
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
