<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Formula\Domain\DependencyOrder;
use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * Formulas in a schema version 2 release (ADR 0023), parsed by the Formula kernel. A DerivedValue
 * field reads the sheet and trackers, and so do the modifier and band bounds of a check (ADR 0024); a band "upTo" and a tracker effect "value" read trackers only.
 *
 * @internal
 */
final class Formulas
{
    private const array FORMULA_REF = ['formula' => true];

    /** The sheet field types a formula may read as {key}. */
    private const array READABLE_FIELDS = ['number', 'track', 'derived'];

    /**
     * {formula: string} in a band "upTo" or a tracker effect "value": the formula reads trackers
     * only, each declared in the release.
     *
     * @return array{formula: string}
     */
    public static function flow(mixed $value, string $path, Catalog $catalog): array
    {
        $ref = ReleaseFields::object($value, $path, self::FORMULA_REF);
        $formulaPath = $path.'.formula';
        $text = ReleaseFields::text($ref['formula'], $formulaPath, 1, Formula::MAX_LENGTH);
        foreach (self::parse($text, $formulaPath)->references() as $reference) {
            if (ReferenceKind::Tracker !== $reference->kind) {
                throw InvalidReleaseContent::at($formulaPath, \sprintf('{%s}: a flow formula reads trackers only, write {tracker:key}', $reference->toString()));
            }

            if (!$catalog->hasTracker($reference->key)) {
                throw InvalidReleaseContent::at($formulaPath, \sprintf('{%s}: unknown tracker "%s"', $reference->toString(), $reference->key));
            }
        }

        return ['formula' => $text];
    }

    /**
     * A formula over the sheet (a check's modifier or band "upTo" formula): it reads what a derived
     * field reads, and {check:difficulty} when $difficulty says the check declares one (null: no
     * check reference is allowed).
     *
     * @param array<string, string> $fieldTypes  the type of every field of the sheet by key
     * @param list<string>          $trackerKeys the trackers of the release
     */
    public static function check(string $text, string $path, array $fieldTypes, array $trackerKeys, ?bool $difficulty): void
    {
        foreach (self::parse($text, $path)->references() as $reference) {
            $problem = self::sheetProblem($reference, $fieldTypes, $trackerKeys, $difficulty);
            if (null !== $problem) {
                throw InvalidReleaseContent::at($path, \sprintf('{%s}: %s', $reference->toString(), $problem));
            }
        }
    }

    /**
     * The formulas of the derived fields of a sheet: each reads number, track and derived fields
     * of the sheet and the trackers of the release, and derived fields never depend on each other
     * in a cycle.
     *
     * @param array<string, array{path: string, formula: string}> $derived     the derived fields by key
     * @param array<string, string>                               $fieldTypes  the type of every field of the sheet by key
     * @param list<string>                                        $trackerKeys the trackers of the release
     */
    public static function sheet(array $derived, array $fieldTypes, array $trackerKeys): void
    {
        $formulas = [];
        foreach ($derived as $key => ['path' => $path, 'formula' => $text]) {
            $formulaPath = $path.'.formula';
            $formulas[$key] = self::parse($text, $formulaPath);
            foreach ($formulas[$key]->references() as $reference) {
                $problem = self::sheetProblem($reference, $fieldTypes, $trackerKeys);
                if (null !== $problem) {
                    throw InvalidReleaseContent::at($formulaPath, \sprintf('{%s}: %s', $reference->toString(), $problem));
                }
            }
        }

        try {
            DependencyOrder::of($formulas);
        } catch (InvalidFormula $cycle) {
            $path = $derived[$cycle->cycle[0] ?? '']['path'] ?? 'sheet.fields';

            throw InvalidReleaseContent::at($path.'.formula', $cycle->getMessage(), $cycle);
        }
    }

    /**
     * Why a sheet formula cannot read the reference, or null when it can. $difficulty is whether the
     * check the formula belongs to declares a difficulty, null outside the bands of a check.
     *
     * @param array<string, string> $fieldTypes
     * @param list<string>          $trackerKeys
     */
    private static function sheetProblem(Reference $reference, array $fieldTypes, array $trackerKeys, ?bool $difficulty = null): ?string
    {
        if (ReferenceKind::Check === $reference->kind) {
            return match (true) {
                null === $difficulty => 'a check reference is read only by the bands of a check',
                'difficulty' !== $reference->key => \sprintf('unknown check value "%s", only "difficulty" can be read', $reference->key),
                !$difficulty => 'the check declares no difficulty',
                default => null,
            };
        }

        if (ReferenceKind::Tracker === $reference->kind) {
            return \in_array($reference->key, $trackerKeys, true) ? null : \sprintf('unknown tracker "%s"', $reference->key);
        }

        $type = $fieldTypes[$reference->key] ?? null;
        if (null === $type) {
            return \sprintf('unknown field "%s"', $reference->key);
        }

        if (ReferenceKind::TrackMax === $reference->kind) {
            return 'track' === $type ? null : \sprintf('field "%s" is a %s field, only a track field has a max', $reference->key, $type);
        }

        return \in_array($type, self::READABLE_FIELDS, true)
            ? null
            : \sprintf('field "%s" is a %s field, a formula reads number, track and derived fields only', $reference->key, $type);
    }

    private static function parse(string $text, string $path): Formula
    {
        try {
            return Formula::fromString($text);
        } catch (InvalidFormula $invalid) {
            throw InvalidReleaseContent::at($path, $invalid->getMessage(), $invalid);
        }
    }
}
