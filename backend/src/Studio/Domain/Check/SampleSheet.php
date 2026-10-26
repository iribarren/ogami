<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;

/**
 * What a test roll's formulas read (ADR 0032): the saved draft's sheet and trackers with the sample
 * values sent for the roll, read the way Play's SheetValues reads a Character's and a Campaign's.
 * Only number, track and conditions fields and trackers take a sample; a number without one reads
 * its default (else 0 moved inside min..max), a track 0, a conditions field 0 marked (a sample is
 * how many of its options are marked, as formulas read it), a tracker its initial value (a clock 0). A derived
 * field is computed from the samples when a formula reads it; one whose formula fails has no value,
 * and neither has a formula that reads it. A track without a fixed max has its sample as max.
 */
final class SampleSheet implements ValueResolver
{
    private const int NUMBER_LIMIT = 1_000_000;
    private const int MAX_TRACK = 999;

    /** @var array<string, ?int> derived values computed so far, null for one that failed */
    private array $derived = [];

    /** @var array<string, true> derived fields being computed, to refuse a cycle */
    private array $computing = [];

    /**
     * @param array<string, int>             $numbers  the value of every number field and the count marked of every conditions field
     * @param array<string, array{int, int}> $tracks   current and max of every track field
     * @param array<string, Formula>         $formulas the formula of every derived field
     * @param array<string, int>             $trackers the value of every tracker
     */
    private function __construct(
        private readonly array $numbers,
        private readonly array $tracks,
        private readonly array $formulas,
        private readonly array $trackers,
    ) {
    }

    /**
     * @param array<string, mixed> $document       the saved draft
     * @param mixed                $sheetSamples   the sent "sheet": number and track values, and the count marked of conditions fields, by field key
     * @param mixed                $trackerSamples the sent "trackers": values by tracker key
     *
     * @throws InvalidTestRoll     when a sample is not an integer within its field's range, or names no number, track or conditions field or tracker
     * @throws CheckTestRollFailed when the draft's sheet or trackers are not readable
     */
    public static function fromDocument(array $document, mixed $sheetSamples, mixed $trackerSamples): self
    {
        $sheetSamples = self::samples($sheetSamples, 'sheet');
        $trackerSamples = self::samples($trackerSamples, 'trackers');

        $fields = self::fields($document);
        $types = [];
        $numbers = $tracks = $formulas = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            $type = $field['type'] ?? null;
            if (!\is_string($key) || !\is_string($type)) {
                throw CheckTestRollFailed::unreadable('a sheet field has no string key and type');
            }

            $types[$key] = $type;
        }

        foreach (array_keys($sheetSamples) as $key) {
            $key = (string) $key;
            match ($types[$key] ?? null) {
                null => throw InvalidTestRoll::at('sheet.'.$key, 'is not a field of the sheet'),
                'derived' => throw InvalidTestRoll::at('sheet.'.$key, 'is derived, so it takes no sample'),
                'number', 'track', 'conditions' => null,
                default => throw InvalidTestRoll::at('sheet.'.$key, 'is not a number, track or conditions field'),
            };
        }

        foreach ($fields as $field) {
            /** @var string $key checked above */
            $key = $field['key'];
            switch ($field['type']) {
                case 'number':
                    $numbers[$key] = self::number($key, $field, $sheetSamples);
                    break;
                case 'track':
                    $tracks[$key] = self::track($key, $field, $sheetSamples);
                    break;
                case 'conditions':
                    $numbers[$key] = self::marked($key, $field, $sheetSamples);
                    break;
                case 'derived':
                    $formulas[$key] = self::formula($key, $field['formula'] ?? null);
                    break;
            }
        }

        return new self($numbers, $tracks, $formulas, self::trackers($document['trackers'] ?? null, $trackerSamples));
    }

    public function value(Reference $reference): int
    {
        $key = $reference->key;
        $value = match ($reference->kind) {
            ReferenceKind::Tracker => $this->trackers[$key] ?? null,
            ReferenceKind::TrackMax => ($this->tracks[$key] ?? [null, null])[1],
            ReferenceKind::Field => $this->numbers[$key] ?? ($this->tracks[$key][0] ?? $this->derived($key, $reference)),
            // Only a check's bands read this, and the check evaluation answers it.
            ReferenceKind::Check => null,
        };

        return $value ?? throw FormulaEvaluationFailed::unknownReference($reference);
    }

    private function derived(string $key, Reference $reference): ?int
    {
        if (!isset($this->formulas[$key])) {
            return null;
        }

        if (!\array_key_exists($key, $this->derived)) {
            if (isset($this->computing[$key])) {
                throw FormulaEvaluationFailed::unknownReference($reference);
            }

            $this->computing[$key] = true;
            try {
                $this->derived[$key] = $this->formulas[$key]->evaluate($this);
            } catch (FormulaEvaluationFailed) {
                $this->derived[$key] = null;
            } finally {
                unset($this->computing[$key]);
            }
        }

        return $this->derived[$key];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws CheckTestRollFailed when the sheet or its fields are not well-formed
     */
    private static function fields(array $document): array
    {
        $sheet = $document['sheet'] ?? null;
        if (null !== $sheet && !\is_array($sheet)) {
            throw CheckTestRollFailed::unreadable('the sheet is not an object');
        }

        return self::items($sheet['fields'] ?? null, 'sheet fields');
    }

    /**
     * A null is absent. A non-empty list is read as an object keyed by its indexes, so each of its
     * items is refused on its own path ("sheet.0"): the request cannot tell a JSON `{}` from `[]`.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidTestRoll when the samples are neither null nor a JSON object or list
     */
    private static function samples(mixed $samples, string $path): array
    {
        if (null === $samples) {
            return [];
        }

        if (!\is_array($samples)) {
            throw InvalidTestRoll::at($path, 'must be an object of '.('sheet' === $path ? 'field' : 'tracker').' keys and integers');
        }

        return $samples;
    }

    /**
     * @param array<array-key, mixed> $field
     * @param array<array-key, mixed> $samples
     */
    private static function number(string $key, array $field, array $samples): int
    {
        $min = self::optionalInt($field['min'] ?? null, $key);
        $max = self::optionalInt($field['max'] ?? null, $key);
        $default = self::optionalInt($field['default'] ?? null, $key);
        $low = $min ?? -self::NUMBER_LIMIT;
        $high = $max ?? self::NUMBER_LIMIT;
        if (!\array_key_exists($key, $samples)) {
            // As Play's NumberField::initial(): the default, else 0 moved inside min..max.
            return $default ?? max($low, min(0, $high));
        }

        return self::sample($samples[$key], 'sheet.'.$key, $low, $high);
    }

    /**
     * How many options of a conditions field are marked: 0 without a sample, else the sample, up to
     * the number of options.
     *
     * @param array<array-key, mixed> $field
     * @param array<array-key, mixed> $samples
     */
    private static function marked(string $key, array $field, array $samples): int
    {
        $options = $field['options'] ?? null;
        if (!\is_array($options) || !array_is_list($options)) {
            throw CheckTestRollFailed::unreadable(\sprintf('the conditions field "%s" has no list of options', $key));
        }

        return \array_key_exists($key, $samples) ? self::sample($samples[$key], 'sheet.'.$key, 0, \count($options)) : 0;
    }

    /**
     * @param array<array-key, mixed> $field
     * @param array<array-key, mixed> $samples
     *
     * @return array{int, int} current and max
     */
    private static function track(string $key, array $field, array $samples): array
    {
        $max = self::optionalInt($field['max'] ?? null, $key);
        $current = \array_key_exists($key, $samples) ? self::sample($samples[$key], 'sheet.'.$key, 0, $max ?? self::MAX_TRACK) : 0;

        return [$current, $max ?? $current];
    }

    private static function formula(string $key, mixed $formula): Formula
    {
        try {
            return Formula::fromString(\is_string($formula) ? $formula : throw CheckTestRollFailed::unreadable(\sprintf('the derived field "%s" has no formula', $key)));
        } catch (InvalidFormula $invalid) {
            throw CheckTestRollFailed::unreadable(\sprintf('the formula of the derived field "%s" does not parse: %s', $key, rtrim($invalid->getMessage(), '.')), $invalid);
        }
    }

    /**
     * @param array<array-key, mixed> $samples
     *
     * @return array<string, int>
     */
    private static function trackers(mixed $stored, array $samples): array
    {
        $values = [];
        foreach (self::items($stored, 'trackers') as $tracker) {
            $key = $tracker['key'] ?? null;
            if (!\is_string($key)) {
                throw CheckTestRollFailed::unreadable('a tracker has no string key');
            }

            [$min, $max, $initial] = match ($tracker['kind'] ?? null) {
                'counter' => [self::requiredInt($tracker['min'] ?? null, $key), self::requiredInt($tracker['max'] ?? null, $key), self::requiredInt($tracker['initial'] ?? null, $key)],
                'clock' => [0, self::requiredInt($tracker['segments'] ?? null, $key), 0],
                default => throw CheckTestRollFailed::unreadable(\sprintf('the tracker "%s" is neither a counter nor a clock', $key)),
            };
            $values[$key] = \array_key_exists($key, $samples) ? self::sample($samples[$key], 'trackers.'.$key, $min, $max) : $initial;
        }

        foreach (array_keys($samples) as $key) {
            if (!isset($values[(string) $key])) {
                throw InvalidTestRoll::at('trackers.'.$key, 'is not a tracker of the draft');
            }
        }

        return $values;
    }

    private static function sample(mixed $value, string $path, int $min, int $max): int
    {
        if (!\is_int($value)) {
            throw InvalidTestRoll::notAnInteger($path);
        }

        return $value >= $min && $value <= $max ? $value : throw InvalidTestRoll::outside($path, $min, $max, $value);
    }

    /**
     * The object items of a stored list; none when it is absent.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws CheckTestRollFailed when the value is not a list of objects
     */
    private static function items(mixed $stored, string $what): array
    {
        if (null === $stored) {
            return [];
        }

        if (!\is_array($stored) || !array_is_list($stored) || [] !== array_filter($stored, static fn (mixed $item): bool => !\is_array($item))) {
            throw CheckTestRollFailed::unreadable(\sprintf('the %s are not a list of objects', $what));
        }

        // Every item is an array, checked above.
        return array_values(array_filter($stored, \is_array(...)));
    }

    private static function optionalInt(mixed $value, string $key): ?int
    {
        return null === $value || \is_int($value) ? $value : throw CheckTestRollFailed::unreadable(\sprintf('the field "%s" has a bound or default that is not an integer', $key));
    }

    private static function requiredInt(mixed $value, string $key): int
    {
        return \is_int($value) ? $value : throw CheckTestRollFailed::unreadable(\sprintf('the tracker "%s" lacks an integer bound', $key));
    }
}
