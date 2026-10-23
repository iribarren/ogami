<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Formula\Domain\DependencyOrder;
use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceOption;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\CloseThreadEffect;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\CreateNpcEffect;
use App\Play\Domain\GameSystem\Flow\CreateThreadEffect;
use App\Play\Domain\GameSystem\Flow\Effect;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\FillFactEffect;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\ItemCount;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\NextSceneEffect;
use App\Play\Domain\GameSystem\Flow\OracleBranches;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\Flow\SceneTitleEffect;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\SwitchSceneTypeEffect;
use App\Play\Domain\GameSystem\Flow\TableBranch;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetGroup;
use App\Play\Domain\GameSystem\Sheet\SheetLayout;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\TableEntryMetadata;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\Theme;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Studio\Application\PublishedReleaseView;

/**
 * Play's anti-corruption layer over Studio's Published Language: turns a PublishedReleaseView into
 * a GameSystemSnapshot. It reads only the documented contract (docs/contracts/gamesystem-release.md),
 * lists the schema versions it supports explicitly, and fails with Play errors, never Studio or
 * Randomness ones. A schema version 1 release has no version 2 parts.
 */
final readonly class GameSystemReleaseTranslator
{
    /** @var list<int> */
    public const array SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    // The credits limits of the contract (v2.schema.json); Play never imports Studio's validator.
    private const int MAX_CREDITS_NOTICE_LENGTH = 2000;
    private const int MAX_CREDITS_LICENSE_LENGTH = 200;
    private const int MAX_CREDITS_URL_LENGTH = 500;

    // The sheet limits of the contract (v2.schema.json), re-checked because a stored release is not trusted.
    private const int MAX_SHEET_FIELDS = 100;
    // The sheet layout limits of the contract (ADR 0031).
    private const int MAX_SHEET_COLUMNS = 2;
    private const int MAX_SHEET_GROUPS = 20;
    private const int MAX_GROUP_COLUMNS = 4;
    private const int MAX_GROUP_SPAN = 2;
    private const int MAX_FIELD_LABEL_LENGTH = 100;
    private const int MAX_FIELD_HINT_LENGTH = 500;
    private const int MAX_FIELD_GROUP_LENGTH = 60;
    private const int MAX_FIELD_SPAN = 4;
    private const int MAX_FIELD_ITEM_LABEL_LENGTH = 60;
    private const int MAX_TEXT_FIELD_LENGTH = 2000;
    private const int MAX_LIST_FIELD_ITEMS = 100;
    private const int MAX_TRACK_FIELD_MAX = 999;
    private const array FIELD_PROPERTIES = [
        'number' => ['min', 'max', 'default'],
        'text' => ['multiline', 'maxLength'],
        'list' => ['itemLabel', 'maxItems'],
        'track' => ['max'],
        'derived' => ['formula'],
    ];

    // The check limits of the contract (v2.schema.json), re-checked because a stored release is not trusted.
    private const int MAX_CHECKS = 50;
    private const int MAX_CHECK_BANDS = 10;
    private const int MAX_BAND_LABEL_LENGTH = 80;
    private const int MAX_CHECK_LABEL_LENGTH = 100;
    private const int MAX_CHECK_HINT_LENGTH = 500;
    private const int MAX_DIFFICULTY_LABEL_LENGTH = 60;
    private const int MAX_DIFFICULTY = 1000;

    // The sheet effect limits of the contract (v2.schema.json, ADR 0027).
    private const int MAX_SHEET_EFFECT_VALUE = 1000;
    private const int MAX_SHEET_EFFECT_DICE_LENGTH = 100;

    private const array PHASE_HOOKS = ['sessionOpening', 'sessionClosing', 'phaseOpening', 'phaseClosing', 'sceneOpening', 'sceneClosing', 'worldTurn'];

    /**
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function translate(PublishedReleaseView $view): GameSystemSnapshot
    {
        if (!\in_array($view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw UnsupportedReleaseSchemaVersion::of($view->gameSystemKey, $view->version, $view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS);
        }

        return 1 === $view->schemaVersion ? $this->fromSchemaVersion1($view) : $this->fromSchemaVersion2($view);
    }

    private function fromSchemaVersion1(PublishedReleaseView $view): GameSystemSnapshot
    {
        $content = $view->content;
        $gameSystem = $this->object($view, $content['gameSystem'] ?? null, 'gameSystem');
        $oracles = $this->object($view, $content['oracles'] ?? null, 'oracles');
        $flow = $this->object($view, $content['flow'] ?? null, 'flow');

        return new GameSystemSnapshot(
            $view->gameSystemKey,
            $this->string($view, $gameSystem['name'] ?? null, 'gameSystem.name'),
            $view->version,
            $this->oracleTables($view, $this->list($view, $oracles['tables'] ?? null, 'oracles.tables')),
            $this->likelihoodOracles($view, $this->list($view, $oracles['likelihood'] ?? null, 'oracles.likelihood'), $this->tableKeys($this->list($view, $oracles['tables'] ?? null, 'oracles.tables'))),
            $this->flowSteps($view, $this->list($view, $flow['steps'] ?? null, 'flow.steps')),
        );
    }

    private function fromSchemaVersion2(PublishedReleaseView $view): GameSystemSnapshot
    {
        $content = $view->content;
        $gameSystem = $this->object($view, $content['gameSystem'] ?? null, 'gameSystem');
        $oracles = $this->object($view, $content['oracles'] ?? null, 'oracles');
        $tables = $this->list($view, $oracles['tables'] ?? null, 'oracles.tables');
        $tags = $this->tags($view, $this->list($view, $content['tags'] ?? [], 'tags'));
        $entries = [];
        foreach ($tables as $index => $table) {
            $path = \sprintf('oracles.tables[%d]', $index);
            $table = $this->object($view, $table, $path);
            $key = $this->string($view, $table['key'] ?? null, $path.'.key');
            $tableEntries = $this->list($view, $table['entries'] ?? null, $path.'.entries');
            foreach ($tableEntries as $entryIndex => $entry) {
                $entryPath = \sprintf('%s.entries[%d]', $path, $entryIndex);
                $entry = $this->object($view, $entry, $entryPath);
                $entries[$key][] = new TableEntryMetadata(
                    $this->optionalString($view, $entry['key'] ?? null, $entryPath.'.key'),
                    $this->optionalString($view, $entry['sceneType'] ?? null, $entryPath.'.sceneType'),
                    $this->effects($view, $entry['effects'] ?? [], $entryPath.'.effects'),
                );
                // The Randomness definition knows only the version 1 entry fields.
                unset($entry['key'], $entry['sceneType'], $entry['effects']);
                $tableEntries[$entryIndex] = $entry;
            }

            $tables[$index] = ['entries' => $tableEntries] + $table;
        }

        // Read first: a derived field of the sheet may read any Tracker of the release.
        $trackers = $this->trackers($view, $this->list($view, $content['trackers'] ?? null, 'trackers'));
        $trackerKeys = array_map(static fn (Tracker $tracker): string => $tracker->key, $trackers);
        // Then the sheet, which the modifier and the bands of a check read.
        $sheet = $this->sheet($view, $content['sheet'] ?? null, $trackerKeys);
        $snapshot = new GameSystemSnapshot(
            $view->gameSystemKey,
            $this->string($view, $gameSystem['name'] ?? null, 'gameSystem.name'),
            $view->version,
            $this->oracleTables($view, $tables),
            $this->likelihoodOracles($view, $this->list($view, $oracles['likelihood'] ?? null, 'oracles.likelihood'), $this->tableKeys($tables)),
            [],
            $trackers,
            $this->factSlots($view, $this->list($view, $content['factSlots'] ?? null, 'factSlots')),
            $this->sceneTypes($view, $this->list($view, $content['sceneTypes'] ?? null, 'sceneTypes')),
            $entries,
            $this->flows($view, $this->list($view, $content['flows'] ?? null, 'flows')),
            $tags,
            $this->theme($view, $content['theme'] ?? null),
            $this->credits($view, $gameSystem['credits'] ?? null),
            $sheet,
            $this->checks($view, $content['checks'] ?? [], $sheet, $trackerKeys),
        );
        $this->assertTagsExist($view, $content, '', $snapshot);

        return $snapshot;
    }

    /**
     * Every Tag key a pick step, count condition or NPC / Thread Effect lists must be one of the
     * release's Tags. Studio checks this on publish; the stored content is not trusted.
     */
    private function assertTagsExist(PublishedReleaseView $view, mixed $node, string $path, GameSystemSnapshot $snapshot): void
    {
        $node = $node instanceof \stdClass ? (array) $node : $node;
        if (!\is_array($node)) {
            return;
        }

        $kind = $node['kind'] ?? null;
        $count = $node['count'] ?? null;
        $tagsPath = $tags = null;
        if (\in_array($kind, ['pick', 'createNpc', 'createThread'], true)) {
            $tagsPath = $path.'.tags';
            $tags = $node['tags'] ?? null;
        } elseif ('condition' === $kind && \is_array($count)) {
            $tagsPath = $path.'.count.tags';
            $tags = $count['tags'] ?? null;
        }

        foreach (\is_array($tags) ? $tags : [] as $index => $key) {
            if (\is_string($key) && !$snapshot->tag($key) instanceof Tag) {
                throw $this->invalid($view, \sprintf('%s[%d]', $tagsPath, $index), \sprintf('unknown tag "%s".', $key));
            }
        }

        if ('fillFact' === $kind) {
            $this->assertFactSlotFilled($view, $node, $path, $snapshot);
        }

        if ('check' === $kind) {
            $this->assertCheckStepMatchesItsCheck($view, $node, $path, $snapshot);
        }

        if ('sheet' === $kind) {
            $this->assertSheetEffectReadsTheSheet($view, $node, $path, $snapshot);
        }

        $this->assertFormulaTrackersDeclared($view, $node, $path, $snapshot);

        foreach ($node as $name => $child) {
            // The bands of a check read the sheet, not only Trackers; checks() has checked them.
            if ('' === $path && 'checks' === $name) {
                continue;
            }

            $this->assertTagsExist($view, $child, \is_int($name) ? \sprintf('%s[%d]', $path, $name) : ('' === $path ? $name : $path.'.'.$name), $snapshot);
        }
    }

    /**
     * A band bound or a tracker Effect value that reads a Tracker, as {"tracker": key} or in a formula,
     * reads one the release declares: an unknown one would fail at runtime. Studio checks this on
     * publish; the stored content is not trusted.
     *
     * @param array<array-key, mixed> $node
     */
    private function assertFormulaTrackersDeclared(PublishedReleaseView $view, array $node, string $path, GameSystemSnapshot $snapshot): void
    {
        // Only a band bound and a tracker effect value: a counter level's upTo is an integer, read elsewhere.
        $values = [];
        if (1 === preg_match('/\.bands\[\d+\]$/', $path)) {
            $values['upTo'] = $node['upTo'] ?? null;
        }
        if ('tracker' === ($node['kind'] ?? null)) {
            $values['value'] = $node['value'] ?? null;
        }
        if ('check' === ($node['kind'] ?? null)) {
            $values['difficulty'] = $node['difficulty'] ?? null;
        }

        foreach ($values as $name => $value) {
            $formula = \is_array($value) ? $this->value($view, $value, $path.'.'.$name) : null;
            foreach ($formula instanceof Formula ? $formula->references() : [] as $reference) {
                if (!$snapshot->tracker($reference->key) instanceof Tracker) {
                    throw $this->invalid($view, $path.'.'.$name, \sprintf('unknown tracker "%s".', $reference->key));
                }
            }
        }
    }

    /**
     * A check step names a check of the release, fixes a difficulty only for a check that declares
     * one (a literal within its min..max), and has an Outcome only for a band of the check. Studio
     * checks this on publish; the stored content is not trusted.
     *
     * @param array<array-key, mixed> $step
     */
    private function assertCheckStepMatchesItsCheck(PublishedReleaseView $view, array $step, string $path, GameSystemSnapshot $snapshot): void
    {
        $key = $step['check'] ?? null;
        $check = \is_string($key) ? $snapshot->check($key) : null;
        if (!$check instanceof Check) {
            throw $this->invalid($view, $path.'.check', \sprintf('unknown check "%s".', \is_string($key) ? $key : ''));
        }

        $difficulty = $step['difficulty'] ?? null;
        if (null !== $difficulty) {
            $range = $check->difficulty ?? throw $this->invalid($view, $path.'.difficulty', \sprintf('check "%s" declares no difficulty.', $check->key));
            if (\is_int($difficulty)) {
                $this->assertFixedDifficulty($view, $difficulty, $path.'.difficulty', $check, $range);
            }
        }

        foreach ($this->list($view, $step['outcomes'] ?? [], $path.'.outcomes') as $index => $outcome) {
            $band = $this->object($view, $outcome, \sprintf('%s.outcomes[%d]', $path, $index))['band'] ?? null;
            if (!\is_string($band) || !array_any($check->bands, static fn (CheckBand $candidate): bool => $candidate->key === $band)) {
                throw $this->invalid($view, \sprintf('%s.outcomes[%d].band', $path, $index), \sprintf('unknown band "%s" of check "%s".', \is_string($band) ? $band : '', $check->key));
            }
        }
    }

    private function assertFixedDifficulty(PublishedReleaseView $view, int $value, string $path, Check $check, CheckDifficulty $range): void
    {
        if (abs($value) > self::MAX_DIFFICULTY) {
            throw $this->invalid($view, $path, \sprintf('must be between %d and %d.', -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY));
        }

        if (null !== $range->min && $value < $range->min) {
            throw $this->invalid($view, $path, \sprintf('must be at least %d, the difficulty min of check "%s", %d given.', $range->min, $check->key, $value));
        }

        if (null !== $range->max && $value > $range->max) {
            throw $this->invalid($view, $path, \sprintf('must be at most %d, the difficulty max of check "%s", %d given.', $range->max, $check->key, $value));
        }
    }

    /**
     * A sheet Effect changes a number or track field of the sheet, and its formula reads that
     * Character's sheet (number, track current and max, derived) and the release's Trackers. Studio
     * checks this on publish; the stored content is not trusted.
     *
     * @param array<array-key, mixed> $effect
     */
    private function assertSheetEffectReadsTheSheet(PublishedReleaseView $view, array $effect, string $path, GameSystemSnapshot $snapshot): void
    {
        $key = $effect['field'] ?? null;
        $field = \is_string($key) ? $snapshot->sheet()->field($key) : null;
        if (!$field instanceof Field) {
            throw $this->invalid($view, $path.'.field', \sprintf('unknown sheet field "%s".', \is_string($key) ? $key : ''));
        }

        if (!$field instanceof NumberField && !$field instanceof TrackField) {
            $type = match (true) {
                $field instanceof DerivedField => 'derived',
                $field instanceof TextField => 'text',
                default => 'list',
            };

            throw $this->invalid($view, $path.'.field', \sprintf('field "%s" is a %s field, a sheet effect changes number and track fields only.', $field->key, $type));
        }

        $formula = \is_array($effect['value'] ?? null) ? $effect['value']['formula'] ?? null : null;
        if (null !== $formula) {
            $fields = [];
            foreach ($snapshot->sheet()->fields() as $sheetField) {
                $fields[$sheetField->key] = $sheetField;
            }

            $this->checkFormula($view, $formula, $path.'.value.formula', $fields, array_map(static fn (Tracker $tracker): string => $tracker->key, $snapshot->trackers()), null);
        }
    }

    /**
     * A fillFact Effect names a Fact slot of the release, and sets a text only for a text slot.
     * Studio checks this on publish; the stored content is not trusted.
     *
     * @param array<array-key, mixed> $effect
     */
    private function assertFactSlotFilled(PublishedReleaseView $view, array $effect, string $path, GameSystemSnapshot $snapshot): void
    {
        $key = $effect['slot'] ?? null;
        $slot = \is_string($key) ? $snapshot->factSlot($key) : null;
        if (!$slot instanceof FactSlot) {
            throw $this->invalid($view, $path.'.slot', \sprintf('unknown fact slot "%s".', \is_string($key) ? $key : ''));
        }

        if (null !== ($effect['text'] ?? null) && FactSlotType::Text !== $slot->type) {
            throw $this->invalid($view, $path.'.text', \sprintf('only a text slot takes a text, "%s" is %s %s slot.', $slot->key, FactSlotType::Npc === $slot->type ? 'an' : 'a', $slot->type->value));
        }
    }

    /**
     * @param list<mixed> $tags
     *
     * @return list<Tag>
     */
    private function tags(PublishedReleaseView $view, array $tags): array
    {
        $snapshots = [];
        foreach ($tags as $index => $tag) {
            $path = \sprintf('tags[%d]', $index);
            $tag = $this->object($view, $tag, $path);
            $snapshots[] = new Tag(
                $this->string($view, $tag['key'] ?? null, $path.'.key'),
                $this->string($view, $tag['label'] ?? null, $path.'.label'),
                $this->optionalString($view, $tag['hint'] ?? null, $path.'.hint'),
                $this->tagScope($view, $tag['appliesTo'] ?? null, $path.'.appliesTo'),
            );
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $flows
     *
     * @return list<Flow>
     */
    private function flows(PublishedReleaseView $view, array $flows): array
    {
        $snapshots = [];
        foreach ($flows as $index => $flow) {
            $path = \sprintf('flows[%d]', $index);
            $flow = $this->object($view, $flow, $path);
            $phases = [];
            foreach ($this->list($view, $flow['phases'] ?? null, $path.'.phases') as $phaseIndex => $phase) {
                $phasePath = \sprintf('%s.phases[%d]', $path, $phaseIndex);
                $phase = $this->phase($view, $phase, $phasePath);
                if (isset($phases[$phase->key])) {
                    throw $this->invalid($view, $phasePath.'.key', \sprintf('duplicate key "%s".', $phase->key));
                }

                $phases[$phase->key] = $phase;
            }

            $snapshots[] = new Flow(
                $this->string($view, $flow['key'] ?? null, $path.'.key'),
                $this->string($view, $flow['name'] ?? null, $path.'.name'),
                $this->optionalString($view, $flow['description'] ?? null, $path.'.description'),
                $this->optionalString($view, $flow['introduction'] ?? null, $path.'.introduction'),
                $this->flag($view, $flow['default'] ?? null, $path.'.default'),
                FlowView::tryFrom($defaultView = $this->string($view, $flow['defaultView'] ?? null, $path.'.defaultView'))
                    ?? throw $this->invalid($view, $path.'.defaultView', \sprintf('unknown default view "%s".', $defaultView)),
                $this->keys($view, $flow['oracles'] ?? [], $path.'.oracles'),
                $this->keys($view, $flow['trackers'] ?? [], $path.'.trackers'),
                [] !== $phases ? array_values($phases) : throw $this->invalid($view, $path.'.phases', 'must list at least one phase.'),
            );
        }

        return $snapshots;
    }

    private function phase(PublishedReleaseView $view, mixed $phase, string $path): Phase
    {
        $phase = $this->object($view, $phase, $path);
        $hooks = [];
        foreach (self::PHASE_HOOKS as $hook) {
            $hooks[$hook] = $this->steps($view, $phase[$hook] ?? [], $path.'.'.$hook);
        }

        return new Phase(
            $this->string($view, $phase['key'] ?? null, $path.'.key'),
            $this->string($view, $phase['name'] ?? null, $path.'.name'),
            $this->optionalString($view, $phase['act'] ?? null, $path.'.act'),
            PhaseMode::tryFrom($mode = $this->string($view, $phase['mode'] ?? null, $path.'.mode'))
                ?? throw $this->invalid($view, $path.'.mode', \sprintf('unknown phase mode "%s".', $mode)),
            $this->selection($view, $phase['selection'] ?? null, $path.'.selection'),
            ...$hooks,
            description: $this->optionalString($view, $phase['description'] ?? null, $path.'.description'),
        );
    }

    private function selection(PublishedReleaseView $view, mixed $selection, string $path): SceneSelection
    {
        $selection = $this->object($view, $selection, $path);
        $sceneTypes = function () use ($view, $selection, $path): array {
            $keys = $this->keys($view, $selection['sceneTypes'] ?? null, $path.'.sceneTypes');

            return [] !== $keys ? $keys : throw $this->invalid($view, $path.'.sceneTypes', 'must list at least one Scene Type.');
        };

        return match ($rule = $this->string($view, $selection['rule'] ?? null, $path.'.rule')) {
            'sequence' => SceneSelection::sequence($sceneTypes()),
            'player' => SceneSelection::player($sceneTypes()),
            'oracle' => SceneSelection::oracle($this->string($view, $selection['table'] ?? null, $path.'.table')),
            default => throw $this->invalid($view, $path.'.rule', \sprintf('unknown selection rule "%s".', $rule)),
        };
    }

    /**
     * @param list<mixed> $trackers
     *
     * @return list<Tracker>
     */
    private function trackers(PublishedReleaseView $view, array $trackers): array
    {
        $snapshots = [];
        foreach ($trackers as $index => $tracker) {
            $path = \sprintf('trackers[%d]', $index);
            $tracker = $this->object($view, $tracker, $path);
            $key = $this->string($view, $tracker['key'] ?? null, $path.'.key');
            $name = $this->string($view, $tracker['name'] ?? null, $path.'.name');
            $hint = $this->optionalString($view, $tracker['hint'] ?? null, $path.'.hint');
            $snapshots[] = match ($kind = $this->string($view, $tracker['kind'] ?? null, $path.'.kind')) {
                'counter' => Tracker::counter(
                    $key,
                    $name,
                    $hint,
                    $this->int($view, $tracker['min'] ?? null, $path.'.min'),
                    $this->int($view, $tracker['max'] ?? null, $path.'.max'),
                    $this->int($view, $tracker['initial'] ?? null, $path.'.initial'),
                    $this->levels($view, $tracker['levels'] ?? [], $path.'.levels'),
                ),
                'clock' => Tracker::clock($key, $name, $hint, $this->int($view, $tracker['segments'] ?? null, $path.'.segments')),
                default => throw $this->invalid($view, $path.'.kind', \sprintf('unknown tracker kind "%s".', $kind)),
            };
        }

        return $snapshots;
    }

    /**
     * @return list<TrackerLevel>
     */
    private function levels(PublishedReleaseView $view, mixed $levels, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $levels, $path) as $index => $level) {
            $levelPath = \sprintf('%s[%d]', $path, $index);
            $level = $this->object($view, $level, $levelPath);
            $upTo = $level['upTo'] ?? null;
            $snapshots[] = new TrackerLevel(
                null === $upTo ? null : $this->int($view, $upTo, $levelPath.'.upTo'),
                $this->string($view, $level['label'] ?? null, $levelPath.'.label'),
            );
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $slots
     *
     * @return list<FactSlot>
     */
    private function factSlots(PublishedReleaseView $view, array $slots): array
    {
        $snapshots = [];
        foreach ($slots as $index => $slot) {
            $path = \sprintf('factSlots[%d]', $index);
            $slot = $this->object($view, $slot, $path);
            $type = $this->string($view, $slot['type'] ?? null, $path.'.type');
            $snapshots[] = new FactSlot(
                $this->string($view, $slot['key'] ?? null, $path.'.key'),
                $this->string($view, $slot['label'] ?? null, $path.'.label'),
                FactSlotType::tryFrom($type) ?? throw $this->invalid($view, $path.'.type', \sprintf('unknown fact slot type "%s".', $type)),
                $this->optionalString($view, $slot['hint'] ?? null, $path.'.hint'),
            );
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $sceneTypes
     *
     * @return list<SceneType>
     */
    private function sceneTypes(PublishedReleaseView $view, array $sceneTypes): array
    {
        $snapshots = [];
        foreach ($sceneTypes as $index => $sceneType) {
            $path = \sprintf('sceneTypes[%d]', $index);
            $sceneType = $this->object($view, $sceneType, $path);
            $snapshots[] = new SceneType(
                $this->string($view, $sceneType['key'] ?? null, $path.'.key'),
                $this->string($view, $sceneType['name'] ?? null, $path.'.name'),
                $this->string($view, $sceneType['purpose'] ?? null, $path.'.purpose'),
                $this->optionalString($view, $sceneType['tips'] ?? null, $path.'.tips'),
                $this->keys($view, $sceneType['oracles'] ?? [], $path.'.oracles'),
                $this->steps($view, $sceneType['setup'] ?? [], $path.'.setup'),
                $this->steps($view, $sceneType['play'] ?? [], $path.'.play'),
                $this->steps($view, $sceneType['closing'] ?? [], $path.'.closing'),
            );
        }

        return $snapshots;
    }

    private function steps(PublishedReleaseView $view, mixed $steps, string $path): StepList
    {
        $snapshots = [];
        $keys = [];
        foreach ($this->list($view, $steps, $path) as $index => $step) {
            $stepPath = \sprintf('%s[%d]', $path, $index);
            $step = $this->object($view, $step, $stepPath);
            $key = $this->string($view, $step['key'] ?? null, $stepPath.'.key');
            if (isset($keys[$key])) {
                throw $this->invalid($view, $stepPath.'.key', \sprintf('duplicate key "%s".', $key));
            }

            $keys[$key] = true;
            $kind = $this->string($view, $step['kind'] ?? null, $stepPath.'.kind');
            $common = [
                'key' => $key,
                'title' => $this->string($view, $step['title'] ?? null, $stepPath.'.title'),
                'prompt' => $this->optionalString($view, $step['prompt'] ?? null, $stepPath.'.prompt'),
                'tip' => $this->optionalString($view, $step['tip'] ?? null, $stepPath.'.tip'),
                'mandatory' => $this->flag($view, $step['mandatory'] ?? null, $stepPath.'.mandatory'),
                'next' => $this->optionalString($view, $step['next'] ?? null, $stepPath.'.next'),
                // A character step completes with a Character, so its own effects may change the sheet.
                'effects' => $this->effects($view, $step['effects'] ?? [], $stepPath.'.effects', 'character' === $kind),
            ];
            $snapshots[] = match ($kind) {
                'prompt' => new PromptStep(...$common, inspiration: $this->keys($view, $step['inspiration'] ?? [], $stepPath.'.inspiration')),
                'oracle' => new OracleStep(
                    ...$common,
                    oracle: $this->string($view, $step['oracle'] ?? null, $stepPath.'.oracle'),
                    likelihood: $this->optionalString($view, $step['likelihood'] ?? null, $stepPath.'.likelihood'),
                    branches: $this->oracleBranches($view, $step['branches'] ?? [], $stepPath.'.branches'),
                ),
                'table' => new TableStep(
                    ...$common,
                    table: $this->string($view, $step['table'] ?? null, $stepPath.'.table'),
                    branches: $this->tableBranches($view, $step['branches'] ?? [], $stepPath.'.branches'),
                    otherwise: null === ($step['otherwise'] ?? null) ? null : $this->outcome($view, $step['otherwise'], $stepPath.'.otherwise'),
                ),
                'roll' => new RollStep(...$common, dice: $this->string($view, $step['dice'] ?? null, $stepPath.'.dice'), bands: $this->bands($view, $step['bands'] ?? [], $stepPath.'.bands')),
                'choice' => new ChoiceStep(
                    ...$common,
                    options: $this->options($view, $step['options'] ?? null, $stepPath.'.options'),
                    skip: $this->optionalString($view, $step['skip'] ?? null, $stepPath.'.skip'),
                ),
                'condition' => $this->conditionStep($view, $common, $step, $stepPath),
                'pick' => $this->pickStep($view, $common, $step, $stepPath),
                'character' => new CharacterStep(...$common),
                'check' => $this->checkStep($view, $common, $step, $stepPath),
                default => throw $this->invalid($view, $stepPath.'.kind', \sprintf('unknown step kind "%s".', $kind)),
            };
        }

        return new StepList($snapshots);
    }

    /**
     * @param array{key: string, title: string, prompt: ?string, tip: ?string, mandatory: bool, next: ?string, effects: list<Effect>} $common
     * @param array<mixed>                                                                                                            $step
     */
    private function conditionStep(PublishedReleaseView $view, array $common, array $step, string $path): ConditionStep
    {
        $tracker = $this->optionalString($view, $step['tracker'] ?? null, $path.'.tracker');
        $count = null === ($step['count'] ?? null) ? null : $this->itemCount($view, $step['count'], $path.'.count');
        if ((null === $tracker) === (!$count instanceof ItemCount)) {
            throw $this->invalid($view, $path, 'must set exactly one of tracker and count.');
        }

        return new ConditionStep(...$common, tracker: $tracker, bands: $this->bands($view, $step['bands'] ?? null, $path.'.bands'), count: $count);
    }

    /**
     * @param array{key: string, title: string, prompt: ?string, tip: ?string, mandatory: bool, next: ?string, effects: list<Effect>} $common
     * @param array<mixed>                                                                                                            $step
     */
    private function checkStep(PublishedReleaseView $view, array $common, array $step, string $path): CheckStep
    {
        $outcomes = [];
        foreach ($this->list($view, $step['outcomes'] ?? [], $path.'.outcomes') as $index => $outcome) {
            $outcomePath = \sprintf('%s.outcomes[%d]', $path, $index);
            $band = $this->string($view, $this->object($view, $outcome, $outcomePath)['band'] ?? null, $outcomePath.'.band');
            if (isset($outcomes[$band])) {
                throw $this->invalid($view, $outcomePath.'.band', \sprintf('duplicate outcome band "%s".', $band));
            }

            // The checked Character is known, so an Outcome may change the sheet.
            $outcomes[$band] = $this->outcome($view, $outcome, $outcomePath, true);
        }

        return new CheckStep(
            ...$common,
            check: $this->string($view, $step['check'] ?? null, $path.'.check'),
            difficulty: null === ($step['difficulty'] ?? null) ? null : $this->value($view, $step['difficulty'], $path.'.difficulty'),
            outcomes: $outcomes,
        );
    }

    private function itemCount(PublishedReleaseView $view, mixed $count, string $path): ItemCount
    {
        $count = $this->object($view, $count, $path);

        return new ItemCount($this->itemKind($view, $count['of'] ?? null, $path.'.of'), $this->keys($view, $count['tags'] ?? [], $path.'.tags'));
    }

    private function theme(PublishedReleaseView $view, mixed $theme): ?string
    {
        $name = $this->optionalString($view, $theme, 'theme');

        return null === $name ? null : (Theme::tryFrom($name) ?? throw $this->invalid($view, 'theme', \sprintf('unknown theme "%s".', $name)))->value;
    }

    /**
     * The optional credits of the GameSystem. Studio checks them on publish; the stored content is
     * not trusted, so the notice, license and link are checked again.
     */
    private function credits(PublishedReleaseView $view, mixed $value): ?Credits
    {
        if (null === $value) {
            return null;
        }

        $path = 'gameSystem.credits';
        $credits = $this->object($view, $value, $path);
        foreach (array_keys($credits) as $name) {
            if (!\in_array($name, ['notice', 'license', 'url'], true)) {
                throw $this->invalid($view, $path.'.'.$name, 'unknown property.');
            }
        }

        $notice = $this->string($view, $credits['notice'] ?? null, $path.'.notice');
        if ('' === trim($notice)) {
            throw $this->invalid($view, $path.'.notice', 'must not be blank.');
        }

        if (mb_strlen(trim($notice)) > self::MAX_CREDITS_NOTICE_LENGTH) {
            throw $this->invalid($view, $path.'.notice', \sprintf('must be at most %d characters.', self::MAX_CREDITS_NOTICE_LENGTH));
        }

        $license = $this->optionalString($view, $credits['license'] ?? null, $path.'.license');
        if (null !== $license && mb_strlen($license) > self::MAX_CREDITS_LICENSE_LENGTH) {
            throw $this->invalid($view, $path.'.license', \sprintf('must be at most %d characters.', self::MAX_CREDITS_LICENSE_LENGTH));
        }

        $url = $this->optionalString($view, $credits['url'] ?? null, $path.'.url');
        if (null !== $url && 1 !== preg_match('~^https://[^\\s/?\#]+\\S*$~u', $url)) {
            throw $this->invalid($view, $path.'.url', 'must be an absolute https:// URL.');
        }

        if (null !== $url && mb_strlen($url) > self::MAX_CREDITS_URL_LENGTH) {
            throw $this->invalid($view, $path.'.url', \sprintf('must be at most %d characters.', self::MAX_CREDITS_URL_LENGTH));
        }

        return new Credits($notice, $license, $url);
    }

    /**
     * The character sheet template of a schema version 2 release (ADR 0022). Studio checks every
     * rule on publish; the stored content is not trusted.
     *
     * @param list<string> $trackerKeys the Trackers of the release, which derived fields may read
     */
    private function sheet(PublishedReleaseView $view, mixed $value, array $trackerKeys): SheetTemplate
    {
        $sheet = $this->object($view, $value ?? [], 'sheet');
        foreach (array_keys($sheet) as $name) {
            if (!\in_array($name, ['fields', 'columns', 'groups'], true)) {
                throw $this->invalid($view, 'sheet.'.$name, 'unknown property.');
            }
        }

        $list = $this->list($view, $sheet['fields'] ?? [], 'sheet.fields');
        if (\count($list) > self::MAX_SHEET_FIELDS) {
            throw $this->invalid($view, 'sheet.fields', \sprintf('at most %d fields.', self::MAX_SHEET_FIELDS));
        }

        $layout = $this->sheetLayout($view, $sheet);
        $fields = $paths = [];
        foreach ($list as $index => $raw) {
            $field = $this->sheetField($view, $raw, $paths[] = $path = \sprintf('sheet.fields[%d]', $index));
            $this->assertFieldFitsTheLayout($view, $layout, $field, \is_array($raw) && null !== ($raw['span'] ?? null), $path);
            if (isset($fields[$field->key])) {
                throw $this->invalid($view, \sprintf('sheet.fields[%d].key', $index), \sprintf('duplicate field key "%s".', $field->key));
            }

            $fields[$field->key] = $field;
        }

        $this->assertDerivedFieldsReadable($view, $fields, array_combine(array_keys($fields), $paths), $trackerKeys);

        return new SheetTemplate(array_values($fields), $layout);
    }

    /**
     * The groups of the sheet layout (ADR 0031), or null when none is declared. Studio checks every
     * rule on publish; the stored content is not trusted.
     *
     * @param array<mixed> $sheet
     */
    private function sheetLayout(PublishedReleaseView $view, array $sheet): ?SheetLayout
    {
        $list = $this->list($view, $sheet['groups'] ?? [], 'sheet.groups');
        if (\count($list) > self::MAX_SHEET_GROUPS) {
            throw $this->invalid($view, 'sheet.groups', \sprintf('at most %d groups.', self::MAX_SHEET_GROUPS));
        }

        $columns = $this->optionalInt($view, $sheet['columns'] ?? null, 'sheet.columns', 1, self::MAX_SHEET_COLUMNS);
        if ([] === $list) {
            return null === $columns ? null : throw $this->invalid($view, 'sheet.columns', 'needs declared groups.');
        }

        $groups = [];
        foreach ($list as $index => $group) {
            $path = \sprintf('sheet.groups[%d]', $index);
            $group = $this->object($view, $group, $path);
            foreach (array_keys($group) as $name) {
                if (!\in_array($name, ['name', 'columns', 'span'], true)) {
                    throw $this->invalid($view, $path.'.'.$name, 'unknown property.');
                }
            }

            $name = $this->boundedString($view, $group['name'] ?? null, $path.'.name', self::MAX_FIELD_GROUP_LENGTH);
            if (isset($groups[$name])) {
                throw $this->invalid($view, $path.'.name', \sprintf('duplicate group name "%s".', $name));
            }

            $span = $this->optionalInt($view, $group['span'] ?? null, $path.'.span', 1, self::MAX_GROUP_SPAN) ?? 1;
            if ($span > ($columns ?? 1)) {
                throw $this->invalid($view, $path.'.span', \sprintf('must be at most the columns of the sheet (%d).', $columns ?? 1));
            }

            $groups[$name] = new SheetGroup($name, $this->optionalInt($view, $group['columns'] ?? null, $path.'.columns', 1, self::MAX_GROUP_COLUMNS) ?? 1, $span);
        }

        return new SheetLayout($columns ?? 1, array_values($groups));
    }

    /**
     * Without a layout a field has no span; with one it names a declared group and spans at most that
     * group's columns.
     *
     * @param bool $spanGiven whether the stored field carries a span, whatever its value
     */
    private function assertFieldFitsTheLayout(PublishedReleaseView $view, ?SheetLayout $layout, Field $field, bool $spanGiven, string $path): void
    {
        if (!$layout instanceof SheetLayout) {
            if ($spanGiven) {
                throw $this->invalid($view, $path.'.span', 'needs declared groups.');
            }

            return;
        }

        $group = null;
        foreach ($layout->groups as $declared) {
            if ($declared->name === $field->group) {
                $group = $declared;
            }
        }

        if (null === $field->group) {
            throw $this->invalid($view, $path.'.group', 'needs one of the declared groups.');
        }

        if (null === $group) {
            throw $this->invalid($view, $path.'.group', \sprintf('unknown group "%s".', $field->group));
        }

        if ($field->span > $group->columns) {
            throw $this->invalid($view, $path.'.span', \sprintf('must be at most the columns of group "%s" (%d).', $group->name, $group->columns));
        }
    }

    /**
     * A derived field reads number, track and derived fields of the sheet and Trackers of the release
     * (a track's max as {key.max}), and derived fields never read each other in a cycle. Studio checks
     * this on publish; the stored content is not trusted.
     *
     * @param array<string, Field>  $fields      by key
     * @param array<string, string> $paths       the path of each field, by key
     * @param list<string>          $trackerKeys
     */
    private function assertDerivedFieldsReadable(PublishedReleaseView $view, array $fields, array $paths, array $trackerKeys): void
    {
        $formulas = [];
        foreach ($fields as $key => $field) {
            if (!$field instanceof DerivedField) {
                continue;
            }

            $formulas[$key] = $field->formula;
            foreach ($field->formula->references() as $reference) {
                $problem = $this->derivedReferenceProblem($reference, $fields, $trackerKeys);
                if (null !== $problem) {
                    throw $this->invalid($view, $paths[$key].'.formula', \sprintf('{%s}: %s.', $reference->toString(), $problem));
                }
            }
        }

        try {
            DependencyOrder::of($formulas);
        } catch (InvalidFormula $cycle) {
            throw $this->invalid($view, ($paths[$cycle->cycle[0] ?? ''] ?? 'sheet.fields').'.formula', $cycle->getMessage());
        }
    }

    /**
     * @param array<string, Field> $fields
     * @param list<string>         $trackerKeys
     */
    private function derivedReferenceProblem(Reference $reference, array $fields, array $trackerKeys): ?string
    {
        if (ReferenceKind::Check === $reference->kind) {
            return 'a check reference is read only by the bands of a check';
        }

        if (ReferenceKind::Tracker === $reference->kind) {
            return \in_array($reference->key, $trackerKeys, true) ? null : \sprintf('unknown tracker "%s"', $reference->key);
        }

        $field = $fields[$reference->key] ?? null;
        if (!$field instanceof Field) {
            return \sprintf('unknown field "%s"', $reference->key);
        }

        if (ReferenceKind::TrackMax === $reference->kind) {
            return $field instanceof TrackField ? null : \sprintf('field "%s" is not a track field, only a track field has a max', $reference->key);
        }

        return $field instanceof NumberField || $field instanceof TrackField || $field instanceof DerivedField
            ? null
            : \sprintf('field "%s" is a text or list field, a formula reads number, track and derived fields only', $reference->key);
    }

    /**
     * The checks of a schema version 2 release (ADR 0024). Studio checks every rule on publish; the
     * stored content is not trusted. The modifier and the bands read the sheet and the release's
     * Trackers; a band also reads {check:difficulty} when its check declares a difficulty.
     *
     * @param list<string> $trackerKeys
     *
     * @return list<Check>
     */
    private function checks(PublishedReleaseView $view, mixed $value, SheetTemplate $sheet, array $trackerKeys): array
    {
        $list = $this->list($view, $value ?? [], 'checks');
        if (\count($list) > self::MAX_CHECKS) {
            throw $this->invalid($view, 'checks', \sprintf('at most %d checks.', self::MAX_CHECKS));
        }

        $fields = [];
        foreach ($sheet->fields() as $field) {
            $fields[$field->key] = $field;
        }

        $checks = [];
        foreach ($list as $index => $item) {
            $path = \sprintf('checks[%d]', $index);
            $check = $this->object($view, $item, $path);
            $this->assertProperties($view, $check, $path, ['key', 'label', 'hint', 'dice', 'modifier', 'difficulty', 'bands']);
            $key = $this->key($view, $check['key'] ?? null, $path.'.key');
            if (isset($checks[$key])) {
                throw $this->invalid($view, $path.'.key', \sprintf('duplicate check key "%s".', $key));
            }

            $difficulty = null === ($check['difficulty'] ?? null) ? null : $this->checkDifficulty($view, $check['difficulty'], $path.'.difficulty');
            $modifier = null === ($check['modifier'] ?? null) ? null : $this->checkFormula($view, $check['modifier'], $path.'.modifier', $fields, $trackerKeys, null);
            $label = $this->boundedString($view, $check['label'] ?? null, $path.'.label', self::MAX_CHECK_LABEL_LENGTH);
            $hint = null === ($check['hint'] ?? null) ? null : $this->boundedString($view, $check['hint'], $path.'.hint', self::MAX_CHECK_HINT_LENGTH, false);
            $dice = $this->dice($view, $check['dice'] ?? null, $path.'.dice');
            $bands = $this->checkBands($view, $check['bands'] ?? null, $path.'.bands', $fields, $trackerKeys, $difficulty instanceof CheckDifficulty);
            try {
                $checks[$key] = new Check($key, $label, $hint, $dice, $modifier, $difficulty, $bands);
            } catch (\InvalidArgumentException $invalid) {
                throw $this->invalid($view, $path.'.bands', $invalid->getMessage());
            }
        }

        return array_values($checks);
    }

    private function dice(PublishedReleaseView $view, mixed $value, string $path): DiceExpression
    {
        try {
            return DiceExpression::fromString($this->string($view, $value, $path));
        } catch (InvalidDiceExpression $invalid) {
            throw $this->invalid($view, $path, 'invalid dice notation: '.$invalid->getMessage());
        }
    }

    private function checkDifficulty(PublishedReleaseView $view, mixed $value, string $path): CheckDifficulty
    {
        $difficulty = $this->object($view, $value, $path);
        $this->assertProperties($view, $difficulty, $path, ['label', 'default', 'min', 'max']);
        $label = $this->boundedString($view, $difficulty['label'] ?? null, $path.'.label', self::MAX_DIFFICULTY_LABEL_LENGTH);
        $default = $this->optionalInt($view, $difficulty['default'] ?? null, $path.'.default', -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY)
            ?? throw $this->invalid($view, $path.'.default', 'required.');
        $min = $this->optionalInt($view, $difficulty['min'] ?? null, $path.'.min', -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY);
        $max = $this->optionalInt($view, $difficulty['max'] ?? null, $path.'.max', -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY);
        if (null !== $min && $min > $default) {
            throw $this->invalid($view, $path.'.min', \sprintf('must be at most default (%d).', $default));
        }

        if (null !== $max && $max < $default) {
            throw $this->invalid($view, $path.'.max', \sprintf('must be at least default (%d).', $default));
        }

        return new CheckDifficulty($label, $default, $min, $max);
    }

    /**
     * @param array<string, Field> $fields
     * @param list<string>         $trackerKeys
     *
     * @return list<CheckBand>
     */
    private function checkBands(PublishedReleaseView $view, mixed $value, string $path, array $fields, array $trackerKeys, bool $difficulty): array
    {
        $list = $this->list($view, $value, $path);
        if ([] === $list || \count($list) > self::MAX_CHECK_BANDS) {
            throw $this->invalid($view, $path, \sprintf('%d to %d bands.', 1, self::MAX_CHECK_BANDS));
        }

        $bands = $keys = [];
        foreach ($list as $index => $item) {
            $bandPath = \sprintf('%s[%d]', $path, $index);
            $band = $this->object($view, $item, $bandPath);
            $this->assertProperties($view, $band, $bandPath, ['key', 'label', 'upTo']);
            $key = $this->key($view, $band['key'] ?? null, $bandPath.'.key');
            if (isset($keys[$key])) {
                throw $this->invalid($view, $bandPath.'.key', \sprintf('duplicate band key "%s".', $key));
            }

            $keys[$key] = true;
            $upTo = $band['upTo'] ?? null;
            $bands[] = new CheckBand(
                $key,
                $this->boundedString($view, $band['label'] ?? null, $bandPath.'.label', self::MAX_CHECK_LABEL_LENGTH),
                \is_int($upTo) || null === $upTo ? $upTo : $this->checkBound($view, $upTo, $bandPath.'.upTo', $fields, $trackerKeys, $difficulty),
            );
        }

        return $bands;
    }

    /**
     * {"tracker": key} (the formula {tracker:key}) or {"formula": string} over the sheet.
     *
     * @param array<string, Field> $fields
     * @param list<string>         $trackerKeys
     */
    private function checkBound(PublishedReleaseView $view, mixed $value, string $path, array $fields, array $trackerKeys, bool $difficulty): Formula
    {
        $bound = $this->object($view, $value, $path);
        if ([] === $bound || (!isset($bound['formula']) && !isset($bound['tracker']))) {
            throw $this->invalid($view, $path, 'must be an integer, {"tracker": key} or {"formula": string}.');
        }

        if (\array_key_exists('formula', $bound)) {
            $this->assertProperties($view, $bound, $path, ['formula']);

            return $this->checkFormula($view, $bound['formula'], $path.'.formula', $fields, $trackerKeys, $difficulty);
        }

        $this->assertProperties($view, $bound, $path, ['tracker']);
        $tracker = $this->key($view, $bound['tracker'], $path.'.tracker');

        return $this->checkFormula($view, '{tracker:'.$tracker.'}', $path.'.tracker', $fields, $trackerKeys, $difficulty);
    }

    /**
     * A formula of a check over the sheet's number, track and derived fields and the release's
     * Trackers. $difficulty is whether the check declares one, null for its modifier, which never
     * reads a check reference; only a band reads {check:difficulty}.
     *
     * @param array<string, Field> $fields
     * @param list<string>         $trackerKeys
     */
    private function checkFormula(PublishedReleaseView $view, mixed $value, string $path, array $fields, array $trackerKeys, ?bool $difficulty): Formula
    {
        $formula = $this->fieldFormula($view, $value, $path);
        foreach ($formula->references() as $reference) {
            $problem = ReferenceKind::Check === $reference->kind && null !== $difficulty
                ? match (true) {
                    'difficulty' !== $reference->key => \sprintf('unknown check value "%s", only "difficulty" can be read', $reference->key),
                    !$difficulty => 'the check declares no difficulty',
                    default => null,
                }
            : $this->derivedReferenceProblem($reference, $fields, $trackerKeys);
            if (null !== $problem) {
                throw $this->invalid($view, $path, \sprintf('{%s}: %s.', $reference->toString(), $problem));
            }
        }

        return $formula;
    }

    /**
     * @param array<mixed> $object
     * @param list<string> $allowed
     */
    private function assertProperties(PublishedReleaseView $view, array $object, string $path, array $allowed): void
    {
        foreach (array_keys($object) as $name) {
            if (!\in_array($name, $allowed, true)) {
                throw $this->invalid($view, $path.'.'.$name, 'unknown property.');
            }
        }
    }

    private function key(PublishedReleaseView $view, mixed $value, string $path): string
    {
        $key = $this->string($view, $value, $path);
        if (1 !== preg_match('/^[a-z0-9-]{1,64}$/', $key)) {
            throw $this->invalid($view, $path, 'must be 1 to 64 characters among a-z, 0-9 and "-".');
        }

        return $key;
    }

    private function sheetField(PublishedReleaseView $view, mixed $value, string $path): Field
    {
        $field = $this->object($view, $value, $path);
        $type = $this->string($view, $field['type'] ?? null, $path.'.type');
        if (!isset(self::FIELD_PROPERTIES[$type])) {
            throw $this->invalid($view, $path.'.type', \sprintf('unknown field type "%s".', $type));
        }

        foreach (array_keys($field) as $name) {
            if (!\in_array($name, ['key', 'label', 'hint', 'group', 'span', 'type', ...self::FIELD_PROPERTIES[$type]], true)) {
                throw $this->invalid($view, $path.'.'.$name, 'unknown property.');
            }
        }

        $key = $this->key($view, $field['key'] ?? null, $path.'.key');

        $common = [
            $key,
            $this->boundedString($view, $field['label'] ?? null, $path.'.label', self::MAX_FIELD_LABEL_LENGTH),
            null === ($field['hint'] ?? null) ? null : $this->boundedString($view, $field['hint'], $path.'.hint', self::MAX_FIELD_HINT_LENGTH, false),
            null === ($field['group'] ?? null) ? null : $this->boundedString($view, $field['group'], $path.'.group', self::MAX_FIELD_GROUP_LENGTH),
            'span' => $this->optionalInt($view, $field['span'] ?? null, $path.'.span', 1, self::MAX_FIELD_SPAN) ?? 1,
        ];

        return match ($type) {
            'number' => $this->numberField($view, $common, $field, $path),
            'text' => new TextField(
                ...$common,
                multiline: $this->flag($view, $field['multiline'] ?? null, $path.'.multiline'),
                maxLength: $this->optionalInt($view, $field['maxLength'] ?? null, $path.'.maxLength', 1, self::MAX_TEXT_FIELD_LENGTH),
            ),
            'list' => new ListField(
                ...$common,
                itemLabel: null === ($field['itemLabel'] ?? null) ? null : $this->boundedString($view, $field['itemLabel'], $path.'.itemLabel', self::MAX_FIELD_ITEM_LABEL_LENGTH),
                maxItems: $this->optionalInt($view, $field['maxItems'] ?? null, $path.'.maxItems', 1, self::MAX_LIST_FIELD_ITEMS),
            ),
            'derived' => new DerivedField(...$common, formula: $this->fieldFormula($view, $field['formula'] ?? null, $path.'.formula')),
            default => new TrackField(...$common, max: $this->optionalInt($view, $field['max'] ?? null, $path.'.max', 1, self::MAX_TRACK_FIELD_MAX)),
        };
    }

    private function fieldFormula(PublishedReleaseView $view, mixed $value, string $path): Formula
    {
        try {
            return Formula::fromString($this->string($view, $value, $path));
        } catch (InvalidFormula $exception) {
            throw $this->invalid($view, $path, $exception->getMessage());
        }
    }

    /**
     * @param array{0: string, 1: string, 2: ?string, 3: ?string, span: int} $common key, label, hint, group, span
     * @param array<mixed>                                                   $field
     */
    private function numberField(PublishedReleaseView $view, array $common, array $field, string $path): NumberField
    {
        $min = $this->optionalInt($view, $field['min'] ?? null, $path.'.min', -NumberField::LIMIT, NumberField::LIMIT);
        $max = $this->optionalInt($view, $field['max'] ?? null, $path.'.max', -NumberField::LIMIT, NumberField::LIMIT);
        $default = $this->optionalInt($view, $field['default'] ?? null, $path.'.default', -NumberField::LIMIT, NumberField::LIMIT);
        if (null !== $min && null !== $max && $max < $min) {
            throw $this->invalid($view, $path.'.max', \sprintf('must be at least min (%d).', $min));
        }

        if (null !== $default && ((null !== $min && $default < $min) || (null !== $max && $default > $max))) {
            throw $this->invalid($view, $path.'.default', 'must be within min..max.');
        }

        return new NumberField(...$common, min: $min, max: $max, default: $default);
    }

    /**
     * A string of at most $max characters, not blank once trimmed unless $nonBlank is false.
     */
    private function boundedString(PublishedReleaseView $view, mixed $value, string $path, int $max, bool $nonBlank = true): string
    {
        $text = $this->string($view, $value, $path);
        if ($nonBlank && '' === trim($text)) {
            throw $this->invalid($view, $path, 'must not be blank.');
        }

        if (mb_strlen($text) > $max) {
            throw $this->invalid($view, $path, \sprintf('must be at most %d characters.', $max));
        }

        return $text;
    }

    private function optionalInt(PublishedReleaseView $view, mixed $value, string $path, int $min, int $max): ?int
    {
        if (null === $value) {
            return null;
        }

        $int = $this->int($view, $value, $path);

        return $int < $min || $int > $max ? throw $this->invalid($view, $path, \sprintf('must be between %d and %d.', $min, $max)) : $int;
    }

    /**
     * The entity kinds a Tag is limited to; absent or null (every kind) is the empty list.
     *
     * @return list<ItemKind>
     */
    private function tagScope(PublishedReleaseView $view, mixed $value, string $path): array
    {
        if (null === $value) {
            return [];
        }

        $list = $this->list($view, $value, $path);
        if ([] === $list) {
            throw $this->invalid($view, $path, 'must not be empty.');
        }

        $kinds = [];
        foreach ($list as $index => $kind) {
            $kindPath = \sprintf('%s[%d]', $path, $index);
            $kinds[] = ItemKind::tryFrom($name = $this->string($view, $kind, $kindPath))
                ?? throw $this->invalid($view, $kindPath, \sprintf('unknown entity kind "%s".', $name));
        }

        return $kinds;
    }

    private function itemKind(PublishedReleaseView $view, mixed $kind, string $path): ItemKind
    {
        return ItemKind::tryFrom($name = $this->string($view, $kind, $path))
            ?? throw $this->invalid($view, $path, \sprintf('unknown item kind "%s".', $name));
    }

    /**
     * @param array{key: string, title: string, prompt: ?string, tip: ?string, mandatory: bool, next: ?string, effects: list<Effect>} $common
     * @param array<mixed>                                                                                                            $step
     */
    private function pickStep(PublishedReleaseView $view, array $common, array $step, string $path): PickStep
    {
        $min = 1;
        $max = 1;
        if (null !== ($step['count'] ?? null)) {
            $count = $this->object($view, $step['count'], $path.'.count');
            $min = $this->int($view, $count['min'] ?? null, $path.'.count.min');
            $max = $this->int($view, $count['max'] ?? null, $path.'.count.max');
            if ($min < 1 || $max < $min || $max > PickStep::MAX_COUNT) {
                throw $this->invalid($view, $path.'.count', \sprintf('must satisfy 1 <= min <= max <= %d.', PickStep::MAX_COUNT));
            }
        }

        $branches = $this->object($view, $step['branches'] ?? [], $path.'.branches');

        return new PickStep(
            ...$common,
            of: $this->itemKind($view, $step['of'] ?? null, $path.'.of'),
            tags: $this->keys($view, $step['tags'] ?? [], $path.'.tags'),
            mode: PickMode::tryFrom($mode = $this->string($view, $step['mode'] ?? null, $path.'.mode'))
                ?? throw $this->invalid($view, $path.'.mode', \sprintf('unknown pick mode "%s".', $mode)),
            min: $min,
            max: $max,
            found: null === ($branches['found'] ?? null) ? null : $this->outcome($view, $branches['found'], $path.'.branches.found'),
            none: null === ($branches['none'] ?? null) ? null : $this->outcome($view, $branches['none'], $path.'.branches.none'),
        );
    }

    /**
     * @param bool $sheet whether the Outcome sits where a Character is known and its effects may change the sheet
     */
    private function outcome(PublishedReleaseView $view, mixed $outcome, string $path, bool $sheet = false): Outcome
    {
        $outcome = $this->object($view, $outcome, $path);

        return new Outcome(
            $this->optionalString($view, $outcome['next'] ?? null, $path.'.next'),
            $this->effects($view, $outcome['effects'] ?? [], $path.'.effects', $sheet),
        );
    }

    private function oracleBranches(PublishedReleaseView $view, mixed $branches, string $path): OracleBranches
    {
        $branches = $this->object($view, $branches, $path);
        $outcomes = [];
        foreach (['yes', 'no', 'exceptionalYes', 'exceptionalNo'] as $answer) {
            $outcomes[$answer] = null === ($branches[$answer] ?? null) ? null : $this->outcome($view, $branches[$answer], $path.'.'.$answer);
        }

        return new OracleBranches(...$outcomes);
    }

    /**
     * @return list<TableBranch>
     */
    private function tableBranches(PublishedReleaseView $view, mixed $branches, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $branches, $path) as $index => $branch) {
            $branchPath = \sprintf('%s[%d]', $path, $index);
            $entry = $this->object($view, $branch, $branchPath)['entry'] ?? null;
            $snapshots[] = new TableBranch($this->string($view, $entry, $branchPath.'.entry'), $this->outcome($view, $branch, $branchPath));
        }

        return $snapshots;
    }

    /**
     * @return list<ChoiceOption>
     */
    private function options(PublishedReleaseView $view, mixed $options, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $options, $path) as $index => $option) {
            $optionPath = \sprintf('%s[%d]', $path, $index);
            $fields = $this->object($view, $option, $optionPath);
            $snapshots[] = new ChoiceOption(
                $this->string($view, $fields['key'] ?? null, $optionPath.'.key'),
                $this->string($view, $fields['label'] ?? null, $optionPath.'.label'),
                $this->outcome($view, $option, $optionPath),
            );
        }

        return $snapshots;
    }

    /**
     * @return list<Band>
     */
    private function bands(PublishedReleaseView $view, mixed $bands, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $bands, $path) as $index => $band) {
            $bandPath = \sprintf('%s[%d]', $path, $index);
            $fields = $this->object($view, $band, $bandPath);
            $upTo = $fields['upTo'] ?? null;
            $label = \array_key_exists('label', $fields) ? $this->boundedString($view, $fields['label'], $bandPath.'.label', self::MAX_BAND_LABEL_LENGTH) : null;
            $snapshots[] = new Band(null === $upTo ? null : $this->value($view, $upTo, $bandPath.'.upTo'), $this->outcome($view, $band, $bandPath), $label);
        }

        return $snapshots;
    }

    /**
     * @param bool $sheet whether the list sits where a Character is known (a check outcome, a character step) and may hold sheet effects
     *
     * @return list<Effect>
     */
    private function effects(PublishedReleaseView $view, mixed $effects, string $path, bool $sheet = false): array
    {
        $snapshots = [];
        foreach ($this->list($view, $effects, $path) as $index => $effect) {
            $effectPath = \sprintf('%s[%d]', $path, $index);
            $effect = $this->object($view, $effect, $effectPath);
            $sceneType = fn (): string => $this->string($view, $effect['sceneType'] ?? null, $effectPath.'.sceneType');
            $kind = $this->string($view, $effect['kind'] ?? null, $effectPath.'.kind');
            if ('sheet' === $kind && !$sheet) {
                throw $this->invalid($view, $effectPath, 'a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step.');
            }

            $snapshots[] = match ($kind) {
                'tracker' => new TrackerEffect(
                    $this->string($view, $effect['tracker'] ?? null, $effectPath.'.tracker'),
                    TrackerOperation::tryFrom($op = $this->string($view, $effect['op'] ?? null, $effectPath.'.op'))
                        ?? throw $this->invalid($view, $effectPath.'.op', \sprintf('unknown tracker operation "%s".', $op)),
                    $this->value($view, $effect['value'] ?? null, $effectPath.'.value'),
                ),
                'nextScene' => new NextSceneEffect($sceneType()),
                'switchSceneType' => new SwitchSceneTypeEffect($sceneType()),
                'endPhase' => new EndPhaseEffect(),
                'sceneTitle' => new SceneTitleEffect($this->string($view, $effect['title'] ?? null, $effectPath.'.title')),
                'createNpc' => new CreateNpcEffect(
                    $this->optionalString($view, $effect['name'] ?? null, $effectPath.'.name'),
                    $this->optionalString($view, $effect['agenda'] ?? null, $effectPath.'.agenda'),
                    $this->keys($view, $effect['tags'] ?? [], $effectPath.'.tags'),
                ),
                'createThread' => new CreateThreadEffect(
                    $this->optionalString($view, $effect['name'] ?? null, $effectPath.'.name'),
                    $this->keys($view, $effect['tags'] ?? [], $effectPath.'.tags'),
                ),
                'closeThread' => new CloseThreadEffect(),
                'fillFact' => new FillFactEffect(
                    $this->string($view, $effect['slot'] ?? null, $effectPath.'.slot'),
                    $this->optionalString($view, $effect['text'] ?? null, $effectPath.'.text'),
                ),
                'sheet' => new SheetEffect(
                    $this->string($view, $effect['field'] ?? null, $effectPath.'.field'),
                    SheetOperation::tryFrom($op = $this->string($view, $effect['op'] ?? null, $effectPath.'.op'))
                        ?? throw $this->invalid($view, $effectPath.'.op', \sprintf('unknown sheet operation "%s".', $op)),
                    $this->sheetValue($view, $effect['value'] ?? null, $effectPath.'.value'),
                ),
                default => throw $this->invalid($view, $effectPath.'.kind', \sprintf('unknown effect kind "%s".', $kind)),
            };
        }

        return $snapshots;
    }

    /**
     * The value of a sheet Effect: a literal, {"formula": string} (its references are checked
     * against the sheet once the release is read) or {"dice": string}.
     */
    private function sheetValue(PublishedReleaseView $view, mixed $value, string $path): int|Formula|DiceExpression
    {
        if (\is_int($value)) {
            if (abs($value) > self::MAX_SHEET_EFFECT_VALUE) {
                throw $this->invalid($view, $path, \sprintf('must be between %d and %d.', -self::MAX_SHEET_EFFECT_VALUE, self::MAX_SHEET_EFFECT_VALUE));
            }

            return $value;
        }

        if (!\is_array($value) || 1 !== \count($value) || (!\array_key_exists('formula', $value) && !\array_key_exists('dice', $value))) {
            throw $this->invalid($view, $path, 'must be an integer, {"formula": string} or {"dice": string}.');
        }

        if (\array_key_exists('formula', $value)) {
            return $this->fieldFormula($view, $value['formula'], $path.'.formula');
        }

        if (\is_string($value['dice']) && mb_strlen($value['dice']) > self::MAX_SHEET_EFFECT_DICE_LENGTH) {
            throw $this->invalid($view, $path.'.dice', \sprintf('must be at most %d characters.', self::MAX_SHEET_EFFECT_DICE_LENGTH));
        }

        return $this->dice($view, $value['dice'], $path.'.dice');
    }

    /**
     * @return list<string>
     */
    private function keys(PublishedReleaseView $view, mixed $keys, string $path): array
    {
        return array_map(fn (mixed $key): string => $this->string($view, $key, $path), $this->list($view, $keys, $path));
    }

    /**
     * A literal, {"tracker": key} (the formula {tracker:key}) or {"formula": string}, which reads
     * Trackers only.
     */
    private function value(PublishedReleaseView $view, mixed $value, string $path): int|Formula
    {
        if (\is_int($value)) {
            return $value;
        }

        $expected = 'must be an integer, {"tracker": key} or {"formula": string}.';
        if (!\is_array($value) || 1 !== \count($value)) {
            throw $this->invalid($view, $path, $expected);
        }

        $tracker = $value['tracker'] ?? null;
        $formula = $value['formula'] ?? null;
        if (!\is_string($tracker) && !\is_string($formula)) {
            throw $this->invalid($view, $path, $expected);
        }

        try {
            $parsed = Formula::fromString(\is_string($tracker) ? '{'.Reference::tracker($tracker)->toString().'}' : (string) $formula);
        } catch (InvalidFormula $exception) {
            throw $this->invalid($view, $path, $exception->getMessage());
        }

        foreach ($parsed->references() as $reference) {
            if (ReferenceKind::Tracker !== $reference->kind) {
                throw $this->invalid($view, $path, \sprintf('{%s}: a flow formula reads trackers only.', $reference->toString()));
            }
        }

        return $parsed;
    }

    /**
     * @param list<mixed> $tables
     */
    private function oracleTables(PublishedReleaseView $view, array $tables): ?OracleTableSet
    {
        // An empty set is valid content, but OracleTableSet requires at least one table.
        if ([] === $tables) {
            return null;
        }

        try {
            return OracleTableSet::fromArray($tables);
        } catch (InvalidOracleTable $exception) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, 'oracles.tables', $exception->getMessage(), $exception);
        }
    }

    /**
     * @param list<mixed> $tables
     *
     * @return list<string> the keys of the tables that have one
     */
    private function tableKeys(array $tables): array
    {
        $keys = [];
        foreach ($tables as $table) {
            if (\is_array($table) && \is_string($table['key'] ?? null)) {
                $keys[] = $table['key'];
            }
        }

        return $keys;
    }

    /**
     * @param list<mixed>  $oracles
     * @param list<string> $tableKeys the keys of the oracle tables a random event rule may name
     *
     * @return list<SnapshotLikelihoodOracle>
     */
    private function likelihoodOracles(PublishedReleaseView $view, array $oracles, array $tableKeys): array
    {
        $snapshots = [];
        foreach ($oracles as $index => $oracle) {
            $path = \sprintf('oracles.likelihood[%d]', $index);
            $definition = $this->object($view, $oracle, $path);
            $key = $this->string($view, $definition['key'] ?? null, $path.'.key');
            $name = $this->string($view, $definition['name'] ?? null, $path.'.name');
            // key, name and the chaos tracker belong to the GameSystem; the rest is the Randomness definition.
            unset($definition['key'], $definition['name']);
            $chaosTracker = null;
            if (\is_array($definition['chaos'] ?? null) && \array_key_exists('tracker', $definition['chaos'])) {
                $chaosTracker = $this->optionalString($view, $definition['chaos']['tracker'], $path.'.chaos.tracker');
                unset($definition['chaos']['tracker']);
            }

            try {
                $likelihood = LikelihoodOracle::fromArray($definition);
            } catch (InvalidLikelihoodOracle $exception) {
                throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, $exception->getMessage(), $exception);
            }

            $randomEventTable = $likelihood->randomEventTable();
            if (null !== $randomEventTable && !\in_array($randomEventTable, $tableKeys, true)) {
                throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path.'.randomEvent.table', \sprintf('unknown oracle table "%s".', $randomEventTable));
            }

            $snapshots[] = new SnapshotLikelihoodOracle($key, $name, $likelihood, $chaosTracker);
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $steps
     *
     * @return list<FlowStep>
     */
    private function flowSteps(PublishedReleaseView $view, array $steps): array
    {
        $flowSteps = [];
        foreach ($steps as $index => $step) {
            $path = \sprintf('flow.steps[%d]', $index);
            $step = $this->object($view, $step, $path);
            $prompt = $step['prompt'] ?? null;

            $flowSteps[] = new FlowStep(
                $this->string($view, $step['key'] ?? null, $path.'.key'),
                $this->string($view, $step['title'] ?? null, $path.'.title'),
                null === $prompt ? null : $this->string($view, $prompt, $path.'.prompt'),
            );
        }

        return $flowSteps;
    }

    /**
     * @return array<mixed>
     */
    private function object(PublishedReleaseView $view, mixed $value, string $path): array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be an object.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(PublishedReleaseView $view, mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be a list.');
        }

        return $value;
    }

    private function string(PublishedReleaseView $view, mixed $value, string $path): string
    {
        if (!\is_string($value)) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be a string.');
        }

        return $value;
    }

    private function optionalString(PublishedReleaseView $view, mixed $value, string $path): ?string
    {
        return null === $value ? null : $this->string($view, $value, $path);
    }

    /**
     * An optional boolean: absent or null is false.
     */
    private function flag(PublishedReleaseView $view, mixed $value, string $path): bool
    {
        return null === $value ? false : (\is_bool($value) ? $value : throw $this->invalid($view, $path, 'must be a boolean.'));
    }

    private function int(PublishedReleaseView $view, mixed $value, string $path): int
    {
        return \is_int($value) ? $value : throw $this->invalid($view, $path, 'must be an integer.');
    }

    private function invalid(PublishedReleaseView $view, string $path, string $reason): InvalidGameSystemRelease
    {
        return InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, $reason);
    }
}
