<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\Band;
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
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\SwitchSceneTypeEffect;
use App\Play\Domain\GameSystem\Flow\TableBranch;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\Flow\TrackerReference;
use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\TableEntryMetadata;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\Theme;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
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

        $snapshot = new GameSystemSnapshot(
            $view->gameSystemKey,
            $this->string($view, $gameSystem['name'] ?? null, 'gameSystem.name'),
            $view->version,
            $this->oracleTables($view, $tables),
            $this->likelihoodOracles($view, $this->list($view, $oracles['likelihood'] ?? null, 'oracles.likelihood'), $this->tableKeys($tables)),
            [],
            $this->trackers($view, $this->list($view, $content['trackers'] ?? null, 'trackers')),
            $this->factSlots($view, $this->list($view, $content['factSlots'] ?? null, 'factSlots')),
            $this->sceneTypes($view, $this->list($view, $content['sceneTypes'] ?? null, 'sceneTypes')),
            $entries,
            $this->flows($view, $this->list($view, $content['flows'] ?? null, 'flows')),
            $tags,
            $this->theme($view, $content['theme'] ?? null),
            $this->credits($view, $gameSystem['credits'] ?? null),
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

        foreach ($node as $name => $child) {
            $this->assertTagsExist($view, $child, \is_int($name) ? \sprintf('%s[%d]', $path, $name) : ('' === $path ? $name : $path.'.'.$name), $snapshot);
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
            $common = [
                'key' => $key,
                'title' => $this->string($view, $step['title'] ?? null, $stepPath.'.title'),
                'prompt' => $this->optionalString($view, $step['prompt'] ?? null, $stepPath.'.prompt'),
                'tip' => $this->optionalString($view, $step['tip'] ?? null, $stepPath.'.tip'),
                'mandatory' => $this->flag($view, $step['mandatory'] ?? null, $stepPath.'.mandatory'),
                'next' => $this->optionalString($view, $step['next'] ?? null, $stepPath.'.next'),
                'effects' => $this->effects($view, $step['effects'] ?? [], $stepPath.'.effects'),
            ];
            $snapshots[] = match ($kind = $this->string($view, $step['kind'] ?? null, $stepPath.'.kind')) {
                'prompt' => new PromptStep(...$common),
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

        $url = $this->optionalString($view, $credits['url'] ?? null, $path.'.url');
        if (null !== $url && 1 !== preg_match('~^https://[^\\s/?\#]+\\S*$~u', $url)) {
            throw $this->invalid($view, $path.'.url', 'must be an absolute https:// URL.');
        }

        return new Credits($notice, $this->optionalString($view, $credits['license'] ?? null, $path.'.license'), $url);
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

    private function outcome(PublishedReleaseView $view, mixed $outcome, string $path): Outcome
    {
        $outcome = $this->object($view, $outcome, $path);

        return new Outcome(
            $this->optionalString($view, $outcome['next'] ?? null, $path.'.next'),
            $this->effects($view, $outcome['effects'] ?? [], $path.'.effects'),
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
            $upTo = $this->object($view, $band, $bandPath)['upTo'] ?? null;
            $snapshots[] = new Band(null === $upTo ? null : $this->value($view, $upTo, $bandPath.'.upTo'), $this->outcome($view, $band, $bandPath));
        }

        return $snapshots;
    }

    /**
     * @return list<Effect>
     */
    private function effects(PublishedReleaseView $view, mixed $effects, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $effects, $path) as $index => $effect) {
            $effectPath = \sprintf('%s[%d]', $path, $index);
            $effect = $this->object($view, $effect, $effectPath);
            $sceneType = fn (): string => $this->string($view, $effect['sceneType'] ?? null, $effectPath.'.sceneType');
            $snapshots[] = match ($kind = $this->string($view, $effect['kind'] ?? null, $effectPath.'.kind')) {
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
                default => throw $this->invalid($view, $effectPath.'.kind', \sprintf('unknown effect kind "%s".', $kind)),
            };
        }

        return $snapshots;
    }

    /**
     * @return list<string>
     */
    private function keys(PublishedReleaseView $view, mixed $keys, string $path): array
    {
        return array_map(fn (mixed $key): string => $this->string($view, $key, $path), $this->list($view, $keys, $path));
    }

    /**
     * A literal or {"tracker": key}.
     */
    private function value(PublishedReleaseView $view, mixed $value, string $path): int|TrackerReference
    {
        if (\is_int($value)) {
            return $value;
        }

        $reference = \is_array($value) ? $value['tracker'] ?? null : null;
        if (!\is_string($reference)) {
            throw $this->invalid($view, $path, 'must be an integer or {"tracker": key}.');
        }

        return new TrackerReference($reference);
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
