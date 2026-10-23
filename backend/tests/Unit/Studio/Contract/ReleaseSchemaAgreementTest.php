<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Contract;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves each JSON Schema file and the Studio domain agree on the same cases: valid files pass
 * both, structural invalid cases fail both, and semantic invalid cases pass the schema (it cannot
 * express them) but fail the domain. Every invalid case names why it fails: the domain message
 * prefix (the path) and, for structural cases, the schema error location (a JSON pointer).
 *
 * JSON Schema reports "required" and "additionalProperties" on the object that holds the
 * keyword, so for a missing or unknown property the schema location is the parent object while
 * the domain path names the property itself.
 *
 * Each case is checked against the schema file of its "schemaVersion" (v1 for any version the
 * contract does not define, whose "const" then fails). Schema version 2 cases are named "v2-…".
 */
#[CoversNothing]
final class ReleaseSchemaAgreementTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v%d.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../contracts/gamesystem-release/v%d.schema.json';
    private const array SCHEMA_VERSIONS = [1, 2];
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Studio/releases';
    private const string PRESETS = __DIR__.'/../../../../presets';

    /**
     * Structural cases: name => [domain message prefix, schema error location].
     */
    private const array STRUCTURAL = [
        'bad-game-system-key' => ['gameSystem.key: ', '/gameSystem/key'],
        'bad-likelihood-key' => ['oracles.likelihood[0].key: ', '/oracles/likelihood/0/key'],
        'bad-step-key' => ['flow.steps[0].key: ', '/flow/steps/0/key'],
        'bad-table-key' => ['oracles.tables: ', '/oracles/tables/0/key'],
        'chaos-min-below-bound' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/chaos/min'],
        'credits-in-schema-version-1' => ['gameSystem.credits: unknown property', '/gameSystem'],
        'description-not-a-string' => ['gameSystem.description: must be a string', '/gameSystem/description'],
        'empty-game-system-name' => ['gameSystem.name: must not be empty', '/gameSystem/name'],
        'empty-levels' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels'],
        'empty-table-entries' => ['oracles.tables: ', '/oracles/tables/0/entries'],
        'entry-min-fractional' => ['oracles.tables: ', '/oracles/tables/0/entries/0/min'],
        'exceptional-percent-too-high' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/exceptionalPercent'],
        'game-system-name-not-a-string' => ['gameSystem.name: must be a string', '/gameSystem/name'],
        'game-system-not-an-object' => ['gameSystem: must be an object', '/gameSystem'],
        'level-label-empty' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels/0/label'],
        'likelihood-not-a-list' => ['oracles.likelihood: must be a list', '/oracles/likelihood'],
        'missing-flow-steps' => ['flow.steps: required', '/flow'],
        'missing-game-system-name' => ['gameSystem.name: required', '/gameSystem'],
        'missing-likelihood-name' => ['oracles.likelihood[0].name: required', '/oracles/likelihood/0'],
        'missing-oracles-likelihood' => ['oracles.likelihood: required', '/oracles'],
        'missing-schema-version' => ['schemaVersion: required', '/'],
        'missing-sheet' => ['sheet: required', '/'],
        'missing-step-title' => ['flow.steps[0].title: required', '/flow/steps/0'],
        'missing-table-entries' => ['oracles.tables[0].entries: required', '/oracles/tables/0'],
        'negative-level-target' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels/0/target'],
        'nested-table-key-bad-pattern' => ['oracles.tables: ', '/oracles/tables/0/entries/0/table'],
        'non-empty-checks' => ['checks: not supported in schema version 1', '/checks'],
        'non-empty-sheet' => ['sheet: not supported in schema version 1', '/sheet'],
        'schema-version-string' => ['schemaVersion: unsupported schema version', '/schemaVersion'],
        'sides-fractional' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'sides-not-an-integer' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'sides-out-of-range' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'step-not-an-object' => ['flow.steps[0]: must be an object', '/flow/steps/0'],
        'steps-not-a-list' => ['flow.steps: must be a list', '/flow/steps'],
        'table-name-empty' => ['oracles.tables: ', '/oracles/tables/0/name'],
        'tables-not-a-list' => ['oracles.tables: must be a list', '/oracles/tables'],
        'top-level-a-list' => ['(root): must be an object', '/'],
        'unknown-chaos-property' => ['oracles.likelihood[0].chaos.step: unknown property', '/oracles/likelihood/0/chaos'],
        'unknown-entry-property' => ['oracles.tables[0].entries[0].note: unknown property', '/oracles/tables/0/entries/0'],
        'unknown-flow-property' => ['flow.start: unknown property', '/flow'],
        'unknown-game-system-property' => ['gameSystem.author: unknown property', '/gameSystem'],
        'unknown-level-property' => ['oracles.likelihood[0].levels[0].color: unknown property', '/oracles/likelihood/0/levels/0'],
        'unknown-likelihood-property' => ['oracles.likelihood[0].mode: unknown property', '/oracles/likelihood/0'],
        'unknown-oracles-property' => ['oracles.dice: unknown property', '/oracles'],
        'unknown-step-property' => ['flow.steps[0].oracle: unknown property', '/flow/steps/0'],
        'unknown-table-property' => ['oracles.tables[0].color: unknown property', '/oracles/tables/0'],
        'unknown-top-level-property' => ['extra: unknown property', '/'],
        'unsupported-schema-version' => ['schemaVersion: unsupported schema version', '/schemaVersion'],
        'weight-zero' => ['oracles.tables: ', '/oracles/tables/0/entries/0/weight'],
        'generated: too-many-tables' => ['oracles.tables: ', '/oracles/tables'],
        'generated: too-many-likelihood-oracles' => ['oracles.likelihood: at most 20 likelihood oracles', '/oracles/likelihood'],
        'generated: too-many-flow-steps' => ['flow.steps: at most 100 steps', '/flow/steps'],
        'generated: too-long-game-system-key' => ['gameSystem.key: ', '/gameSystem/key'],
        'generated: too-long-game-system-name' => ['gameSystem.name: must be at most 100 characters', '/gameSystem/name'],
        'generated: too-long-description' => ['gameSystem.description: must be at most 2000 characters', '/gameSystem/description'],
        'generated: too-long-likelihood-name' => ['oracles.likelihood[0].name: must be at most 500 characters', '/oracles/likelihood/0/name'],
        'generated: too-long-step-title' => ['flow.steps[0].title: must be at most 100 characters', '/flow/steps/0/title'],
        'generated: too-long-step-prompt' => ['flow.steps[0].prompt: must be at most 2000 characters', '/flow/steps/0/prompt'],
        'generated: entry-text-too-long' => ['oracles.tables: ', '/oracles/tables/0/entries/0/text'],
        'v2-bad-entry-key' => ['oracles.tables[0].entries[0].key: must be 1 to 64 characters', '/oracles/tables/0/entries/0/key'],
        'v2-band-up-to-string' => ['sceneTypes[0].play[0].bands[0].upTo: must be an integer, {tracker: key} or {formula: string}', '/sceneTypes/0/play/0/bands/0/upTo'],
        'v2-choice-one-option' => ['sceneTypes[0].play[0].options: at least 2 options', '/sceneTypes/0/play/0/options'],
        'v2-credits-empty-notice' => ['gameSystem.credits.notice: must not be empty', '/gameSystem/credits/notice'],
        'v2-credits-http-url' => ['gameSystem.credits.url: must be an absolute https:// URL', '/gameSystem/credits/url'],
        'v2-credits-missing-notice' => ['gameSystem.credits.notice: required', '/gameSystem/credits'],
        'v2-credits-relative-url' => ['gameSystem.credits.url: must be an absolute https:// URL', '/gameSystem/credits/url'],
        'v2-credits-unknown-field' => ['gameSystem.credits.author: unknown property', '/gameSystem/credits'],
        'v2-character-step-with-branches' => ['sceneTypes[0].play[0].branches: unknown property', '/sceneTypes/0/play/0'],
        'v2-sheet-label-empty' => ['sheet.fields[0].label: must not be empty', '/sheet/fields/0/label'],
        'v2-sheet-list-with-multiline' => ['sheet.fields[0].multiline: unknown property', '/sheet/fields/0'],
        'v2-sheet-missing-type' => ['sheet.fields[0].type: required', '/sheet/fields/0'],
        'v2-sheet-number-with-max-length' => ['sheet.fields[0].maxLength: unknown property', '/sheet/fields/0'],
        'v2-sheet-text-max-length-zero' => ['sheet.fields[0].maxLength: must be at least 1', '/sheet/fields/0/maxLength'],
        'v2-sheet-track-max-1000' => ['sheet.fields[0].max: must be at most 999', '/sheet/fields/0/max'],
        'v2-sheet-track-with-min' => ['sheet.fields[0].min: unknown property', '/sheet/fields/0'],
        'v2-sheet-unknown-field-type' => ['sheet.fields[0].type: must be one of', '/sheet/fields/0/type'],
        'v2-sheet-unknown-property' => ['sheet.layout: unknown property', '/sheet'],
        'v2-clock-with-min' => ['trackers[0].min: unknown property', '/trackers/0'],
        'v2-condition-mandatory' => ['sceneTypes[0].play[0].mandatory: a condition step is never mandatory', '/sceneTypes/0/play/0/mandatory'],
        'v2-counter-missing-initial' => ['trackers[0].initial: required', '/trackers/0'],
        'v2-duplicate-scene-type-oracle' => ['sceneTypes[0].oracles[1]: duplicate oracle "fate"', '/sceneTypes/0/oracles'],
        'v2-condition-both' => ['sceneTypes[0].play[0]: give either tracker or count, not both', '/sceneTypes/0/play/0'],
        'v2-condition-count-bad-of' => ['sceneTypes[0].play[0].count.of: must be one of', '/sceneTypes/0/play/0/count/of'],
        'v2-condition-count-no-of' => ['sceneTypes[0].play[0].count.of: required', '/sceneTypes/0/play/0/count'],
        'v2-condition-neither' => ['sceneTypes[0].play[0]: requires tracker or count', '/sceneTypes/0/play/0'],
        'v2-effect-close-thread-with-tags' => ['sceneTypes[0].play[0].effects[0].tags: unknown property', '/sceneTypes/0/play/0/effects/0'],
        'v2-effect-npc-empty-name' => ['sceneTypes[0].play[0].effects[0].name: must not be empty', '/sceneTypes/0/play/0/effects/0/name'],
        'v2-effect-thread-with-agenda' => ['sceneTypes[0].play[0].effects[0].agenda: unknown property', '/sceneTypes/0/play/0/effects/0'],
        'v2-pick-bad-mode' => ['sceneTypes[0].play[0].mode: must be one of', '/sceneTypes/0/play/0/mode'],
        'v2-pick-bad-of' => ['sceneTypes[0].play[0].of: must be one of', '/sceneTypes/0/play/0/of'],
        'v2-pick-count-max-11' => ['sceneTypes[0].play[0].count.max: must be at most 10', '/sceneTypes/0/play/0/count/max'],
        'v2-pick-count-min-string' => ['sceneTypes[0].play[0].count.min: must be an integer', '/sceneTypes/0/play/0/count/min'],
        'v2-pick-count-min-zero' => ['sceneTypes[0].play[0].count.min: must be at least 1', '/sceneTypes/0/play/0/count/min'],
        'v2-pick-count-not-an-object' => ['sceneTypes[0].play[0].count: must be an object', '/sceneTypes/0/play/0/count'],
        'v2-pick-too-many-tags' => ['sceneTypes[0].play[0].tags: at most 5 tags', '/sceneTypes/0/play/0/tags'],
        'v2-pick-unknown-property' => ['sceneTypes[0].play[0].weight: unknown property', '/sceneTypes/0/play/0'],
        'v2-random-event-extra-field' => ['oracles.likelihood[0].randomEvent.when: unknown property', '/oracles/likelihood/0/randomEvent'],
        'v2-tag-bad-key' => ['tags[0].key: must be 1 to 64 characters', '/tags/0/key'],
        'v2-tag-empty-label' => ['tags[0].label: must not be empty', '/tags/0/label'],
        'v2-tags-not-a-list' => ['tags: must be a list', '/tags'],
        'v2-theme-not-a-string' => ['theme: must be one of "parchment", "neon", "noir", "eldritch", "starship", 3 given', '/theme'],
        'v2-theme-unknown' => ['theme: must be one of "parchment", "neon", "noir", "eldritch", "starship", \'steampunk\' given', '/theme'],
        'v2-effect-bad-op' => ['sceneTypes[0].play[0].effects[0].op: must be one of', '/sceneTypes/0/play/0/effects/0/op'],
        'v2-effect-unknown-kind' => ['sceneTypes[0].play[0].effects[0].kind: must be one of', '/sceneTypes/0/play/0/effects/0/kind'],
        'v2-effect-fill-fact-bad-slot-key' => ['sceneTypes[0].play[0].effects[0].slot: must be 1 to 64 characters', '/sceneTypes/0/play/0/effects/0/slot'],
        'v2-effect-fill-fact-empty-text' => ['sceneTypes[0].play[0].effects[0].text: must not be empty', '/sceneTypes/0/play/0/effects/0/text'],
        'v2-effect-fill-fact-unknown-property' => ['sceneTypes[0].play[0].effects[0].tags: unknown property', '/sceneTypes/0/play/0/effects/0'],
        'v2-effect-fill-fact-without-slot' => ['sceneTypes[0].play[0].effects[0].slot: required', '/sceneTypes/0/play/0/effects/0'],
        'v2-fact-slot-bad-type' => ['factSlots[0].type: must be one of', '/factSlots/0/type'],
        'v2-flow-bad-default-view' => ['flows[0].defaultView: must be one of', '/flows/0/defaultView'],
        'v2-flow-key' => ['flow: unknown property', '/'],
        'v2-flow-no-phases' => ['flows[0].phases: at least 1 phases', '/flows/0/phases'],
        'v2-level-up-to-tracker' => ['trackers[0].levels[0].upTo: must be an integer', '/trackers/0/levels/0/upTo'],
        'v2-missing-flows' => ['flows: required', '/'],
        'v2-non-empty-sheet' => ['sheet.hp: unknown property', '/sheet'],
        'v2-phase-bad-mode' => ['flows[0].phases[0].mode: must be one of', '/flows/0/phases/0/mode'],
        'v2-player-duplicate-scene-type' => ['flows[0].phases[0].selection.sceneTypes[1]: duplicate Scene Type "scene"', '/flows/0/phases/0/selection/sceneTypes'],
        'v2-scene-type-missing-purpose' => ['sceneTypes[0].purpose: required', '/sceneTypes/0'],
        'v2-segments-out-of-range' => ['trackers[0].segments: must be at most 20', '/trackers/0/segments'],
        'v2-selection-bad-rule' => ['flows[0].phases[0].selection.rule: must be one of', '/flows/0/phases/0/selection/rule'],
        'v2-step-field-of-another-kind' => ['sceneTypes[0].play[0].dice: unknown property', '/sceneTypes/0/play/0'],
        'v2-step-key-end' => ['sceneTypes[0].play[0].key: "end" is reserved', '/sceneTypes/0/play/0/key'],
        'v2-step-unknown-kind' => ['sceneTypes[0].play[0].kind: must be one of', '/sceneTypes/0/play/0/kind'],
        'v2-unknown-phase-hook' => ['flows[0].phases[0].dawn: unknown property', '/flows/0/phases/0'],
        'v2-unknown-tracker-kind' => ['trackers[0].kind: must be one of', '/trackers/0/kind'],
        'generated: v2-too-many-trackers' => ['trackers: at most 50 trackers', '/trackers'],
        'generated: v2-too-many-fact-slots' => ['factSlots: at most 100 fact slots', '/factSlots'],
        'generated: v2-too-long-fact-slot-hint' => ['factSlots[0].hint: must be at most 500 characters', '/factSlots/0/hint'],
        'generated: v2-too-long-fill-fact-text' => ['sceneTypes[0].play[0].effects[0].text: must be at most 2000 characters', '/sceneTypes/0/play/0/effects/0/text'],
        'generated: v2-too-many-levels' => ['trackers[0].levels: at most 20 levels', '/trackers/0/levels'],
        'generated: v2-too-long-tracker-hint' => ['trackers[0].hint: must be at most 500 characters', '/trackers/0/hint'],
        'generated: v2-too-many-steps' => ['sceneTypes[0].play: at most 50 steps', '/sceneTypes/0/play'],
        'generated: v2-too-many-effects' => ['sceneTypes[0].play[0].effects: at most 10 effects', '/sceneTypes/0/play/0/effects'],
        'generated: v2-too-many-tags' => ['tags: at most 50 tags', '/tags'],
        'generated: v2-too-long-tag-hint' => ['tags[0].hint: must be at most 500 characters', '/tags/0/hint'],
        'generated: v2-tag-applies-to-empty' => ['tags[0].appliesTo: at least 1', '/tags/0/appliesTo'],
        'generated: v2-tag-applies-to-unknown-kind' => ['tags[0].appliesTo[1]: must be one of', '/tags/0/appliesTo/1'],
        'generated: v2-tag-applies-to-duplicate-kind' => ['tags[0].appliesTo[1]: duplicate kind "npc"', '/tags/0/appliesTo'],
        'generated: v2-too-long-npc-agenda' => ['sceneTypes[0].play[0].effects[0].agenda: must be at most 2000 characters', '/sceneTypes/0/play/0/effects/0/agenda'],
        'generated: v2-too-long-thread-name' => ['sceneTypes[0].play[0].effects[0].name: must be at most 200 characters', '/sceneTypes/0/play/0/effects/0/name'],
        'generated: v2-sheet-too-many-fields' => ['sheet.fields: at most 100 fields', '/sheet/fields'],
        'generated: v2-sheet-label-too-long' => ['sheet.fields[0].label: must be at most 100 characters', '/sheet/fields/0/label'],
        'generated: v2-sheet-columns-0' => ['sheet.columns: must be at least 1', '/sheet/columns'],
        'generated: v2-sheet-columns-3' => ['sheet.columns: must be at most 2', '/sheet/columns'],
        'generated: v2-sheet-columns-string' => ['sheet.columns: must be an integer', '/sheet/columns'],
        'generated: v2-sheet-groups-object' => ['sheet.groups: must be a list', '/sheet/groups'],
        'generated: v2-sheet-21-groups' => ['sheet.groups: at most 20 groups', '/sheet/groups'],
        'generated: v2-sheet-group-without-name' => ['sheet.groups[0].name: required', '/sheet/groups/0'],
        'generated: v2-sheet-group-name-empty' => ['sheet.groups[0].name: must not be empty', '/sheet/groups/0/name'],
        'generated: v2-sheet-group-name-61' => ['sheet.groups[0].name: must be at most 60 characters', '/sheet/groups/0/name'],
        'generated: v2-sheet-group-columns-5' => ['sheet.groups[0].columns: must be at most 4', '/sheet/groups/0/columns'],
        'generated: v2-sheet-group-span-3' => ['sheet.groups[0].span: must be at most 2', '/sheet/groups/0/span'],
        'generated: v2-sheet-group-span-string' => ['sheet.groups[0].span: must be an integer', '/sheet/groups/0/span'],
        'generated: v2-sheet-group-unknown-property' => ['sheet.groups[0].color: unknown property', '/sheet/groups/0'],
        'generated: v2-sheet-field-span-0' => ['sheet.fields[0].span: must be at least 1', '/sheet/fields/0/span'],
        'generated: v2-sheet-field-span-5' => ['sheet.fields[0].span: must be at most 4', '/sheet/fields/0/span'],
        'generated: v2-too-many-flows' => ['flows: at most 20 flows', '/flows'],
        'generated: v2-too-long-credits-notice' => ['gameSystem.credits.notice: must be at most 2000 characters', '/gameSystem/credits/notice'],
        'generated: v2-too-long-credits-license' => ['gameSystem.credits.license: must be at most 200 characters', '/gameSystem/credits/license'],
        'generated: v2-too-long-credits-url' => ['gameSystem.credits.url: must be at most 500 characters', '/gameSystem/credits/url'],
        'generated: v2-too-long-introduction' => ['flows[0].introduction: must be at most 5000 characters', '/flows/0/introduction'],
        'generated: v2-derived-without-formula' => ['sheet.fields[0].formula: required', '/sheet/fields/0'],
        'generated: v2-derived-empty-formula' => ['sheet.fields[0].formula: must not be empty', '/sheet/fields/0/formula'],
        'generated: v2-derived-formula-too-long' => ['sheet.fields[0].formula: must be at most 200 characters', '/sheet/fields/0/formula'],
        'generated: v2-derived-formula-not-a-string' => ['sheet.fields[0].formula: must be a string', '/sheet/fields/0/formula'],
        'generated: v2-derived-with-min' => ['sheet.fields[0].min: unknown property', '/sheet/fields/0'],
        'generated: v2-band-formula-with-extra-property' => ['sceneTypes[0].play[0].bands[0].upTo.plus: unknown property', '/sceneTypes/0/play/0/bands/0/upTo'],
        'generated: v2-band-formula-empty' => ['sceneTypes[0].play[0].bands[0].upTo.formula: must not be empty', '/sceneTypes/0/play/0/bands/0/upTo/formula'],
        'generated: v2-band-label-empty' => ['sceneTypes[0].play[0].bands[0].label: must not be empty', '/sceneTypes/0/play/0/bands/0/label'],
        'generated: v2-band-label-blank' => ['sceneTypes[0].play[0].bands[0].label: must not be blank', '/sceneTypes/0/play/0/bands/0/label'],
        'generated: v2-band-label-too-long' => ['sceneTypes[0].play[0].bands[0].label: must be at most 80 characters', '/sceneTypes/0/play/0/bands/0/label'],
        'generated: v2-band-label-not-a-string' => ['sceneTypes[0].play[0].bands[0].label: must be a string', '/sceneTypes/0/play/0/bands/0/label'],
        'generated: v2-effect-formula-with-extra-property' => ['sceneTypes[0].play[0].effects[0].value.plus: unknown property', '/sceneTypes/0/play/0/effects/0/value'],
        'generated: v2-effect-formula-too-long' => ['sceneTypes[0].play[0].effects[0].value.formula: must be at most 200 characters', '/sceneTypes/0/play/0/effects/0/value/formula'],
        'generated: v2-counter-level-formula' => ['trackers[0].levels[0].upTo: must be an integer', '/trackers/0/levels/0/upTo'],
        'generated: v2-counter-level-tracker' => ['trackers[0].levels[0].upTo: must be an integer', '/trackers/0/levels/0/upTo'],
        'generated: v2-check-unknown-property' => ['checks[0].roll: unknown property', '/checks/0'],
        'generated: v2-check-missing-dice' => ['checks[0].dice: required', '/checks/0'],
        'generated: v2-check-no-bands' => ['checks[0].bands: at least 1 bands', '/checks/0/bands'],
        'generated: v2-check-too-many-bands' => ['checks[0].bands: at most 10 bands', '/checks/0/bands'],
        'generated: v2-too-many-checks' => ['checks: at most 50 checks', '/checks'],
        'generated: v2-check-dice-too-long' => ['checks[0].dice: must be at most 100 characters', '/checks/0/dice'],
        'generated: v2-check-modifier-too-long' => ['checks[0].modifier: must be at most 200 characters', '/checks/0/modifier'],
        'generated: v2-check-hint-too-long' => ['checks[0].hint: must be at most 500 characters', '/checks/0/hint'],
        'generated: v2-check-label-empty' => ['checks[0].label: must not be empty', '/checks/0/label'],
        'generated: v2-check-bad-key' => ['checks[0].key: must be 1 to 64 characters', '/checks/0/key'],
        'generated: v2-check-difficulty-default-out-of-range' => ['checks[0].difficulty.default: must be at most 1000', '/checks/0/difficulty/default'],
        'generated: v2-check-difficulty-missing-default' => ['checks[0].difficulty.default: required', '/checks/0/difficulty'],
        'generated: v2-check-difficulty-label-empty' => ['checks[0].difficulty.label: must not be empty', '/checks/0/difficulty/label'],
        'generated: v2-check-difficulty-unknown-property' => ['checks[0].difficulty.step: unknown property', '/checks/0/difficulty'],
        'generated: v2-check-band-up-to-string' => ['checks[0].bands[0].upTo: must be an integer, {tracker: key} or {formula: string}', '/checks/0/bands/0/upTo'],
        'generated: v2-check-band-missing-key' => ['checks[0].bands[0].key: required', '/checks/0/bands/0'],
        'generated: v2-check-band-unknown-property' => ['checks[0].bands[0].next: unknown property', '/checks/0/bands/0'],
        'generated: v2-check-band-formula-empty' => ['checks[0].bands[0].upTo.formula: must not be empty', '/checks/0/bands/0/upTo/formula'],
        'generated: v2-check-step-missing-check' => ['sceneTypes[0].play[0].check: required', '/sceneTypes/0/play/0'],
        'generated: v2-check-step-unknown-property' => ['sceneTypes[0].play[0].bands: unknown property', '/sceneTypes/0/play/0'],
        'generated: v2-check-step-difficulty-string' => ['sceneTypes[0].play[0].difficulty: must be an integer, {tracker: key} or {formula: string}', '/sceneTypes/0/play/0/difficulty'],
        'generated: v2-check-step-outcome-missing-band' => ['sceneTypes[0].play[0].outcomes[0].band: required', '/sceneTypes/0/play/0/outcomes/0'],
        'generated: v2-check-step-outcome-unknown-property' => ['sceneTypes[0].play[0].outcomes[0].prompt: unknown property', '/sceneTypes/0/play/0/outcomes/0'],
        'generated: v2-check-step-too-many-outcomes' => ['sceneTypes[0].play[0].outcomes: at most 10 outcomes', '/sceneTypes/0/play/0/outcomes'],
        'generated: v2-too-long-step-tip' => ['sceneTypes[0].play[0].tip: must be at most 2000 characters', '/sceneTypes/0/play/0/tip'],
        'generated: v2-too-long-phase-description' => ['flows[0].phases[0].description: must be at most 2000 characters', '/flows/0/phases/0/description'],
        'generated: v2-sheet-effect-bad-op' => ['sceneTypes[0].play[0].outcomes[0].effects[0].op: ', '/sceneTypes/0/play/0/outcomes/0/effects/0'],
        'generated: v2-sheet-effect-unknown-property' => ['sceneTypes[0].play[0].outcomes[0].effects[0].tracker: unknown property', '/sceneTypes/0/play/0/outcomes/0/effects/0'],
        'generated: v2-sheet-effect-missing-field' => ['sceneTypes[0].play[0].outcomes[0].effects[0].field: required', '/sceneTypes/0/play/0/outcomes/0/effects/0'],
        'generated: v2-sheet-effect-value-out-of-range' => ['sceneTypes[0].play[0].outcomes[0].effects[0].value: ', '/sceneTypes/0/play/0/outcomes/0/effects/0/value'],
        'generated: v2-sheet-effect-dice-too-long' => ['sceneTypes[0].play[0].outcomes[0].effects[0].value.dice: must be at most 100 characters', '/sceneTypes/0/play/0/outcomes/0/effects/0/value/dice'],
        'generated: v2-sheet-effect-dice-not-a-string' => ['sceneTypes[0].play[0].outcomes[0].effects[0].value.dice: must be a string', '/sceneTypes/0/play/0/outcomes/0/effects/0/value/dice'],
        'generated: v2-sheet-effect-value-formula-and-dice' => ['sceneTypes[0].play[0].outcomes[0].effects[0].value.dice: unknown property', '/sceneTypes/0/play/0/outcomes/0/effects/0/value'],
        'v2-inspiration-too-many' => ['sceneTypes[0].play[0].inspiration: at most 5 inspiration tables, 6 given', '/sceneTypes/0/play/0/inspiration'],
        'v2-inspiration-empty' => ['sceneTypes[0].play[0].inspiration: at least 1 inspiration tables, 0 given', '/sceneTypes/0/play/0/inspiration'],
        'v2-inspiration-duplicate' => ['sceneTypes[0].play[0].inspiration[1]: duplicate inspiration table "nouns"', '/sceneTypes/0/play/0/inspiration'],
    ];

    /**
     * Semantic cases: name => domain message prefix (the schema accepts them).
     */
    private const array SEMANTIC = [
        'generated: v2-check-invalid-dice' => 'checks[0].dice: invalid dice notation',
        'generated: v2-check-modifier-syntax' => 'checks[0].modifier: ',
        'generated: v2-check-modifier-unknown-field' => 'checks[0].modifier: {nope}: unknown field "nope"',
        'generated: v2-check-modifier-reads-check' => 'checks[0].modifier: {check:difficulty}: a check reference is read only by the bands of a check',
        'generated: v2-check-modifier-reads-text-field' => 'checks[0].modifier: {name}: field "name" is a text field',
        'generated: v2-check-band-reads-undeclared-difficulty' => 'checks[0].bands[0].upTo.formula: {check:difficulty}: the check declares no difficulty',
        'generated: v2-check-band-reads-other-check-value' => 'checks[0].bands[0].upTo.formula: {check:target}: unknown check value "target"',
        'generated: v2-check-band-formula-unknown-field' => 'checks[0].bands[0].upTo.formula: {nope}: unknown field "nope"',
        'generated: v2-check-band-unknown-tracker' => 'checks[0].bands[0].upTo.tracker: unknown tracker "ghost"',
        'generated: v2-check-duplicate-band-key' => 'checks[0].bands[1].key: duplicate band key "miss"',
        'generated: v2-check-band-without-up-to' => 'checks[0].bands[0].upTo: required, only the last band omits it',
        'generated: v2-check-last-band-with-up-to' => 'checks[0].bands[1].upTo: the last band catches the rest and must omit upTo',
        'generated: v2-check-bands-not-increasing' => 'checks[0].bands[1].upTo: must be greater than 5',
        'generated: v2-check-difficulty-min-above-default' => 'checks[0].difficulty.min: must be at most default (9), 12 given',
        'generated: v2-check-difficulty-max-below-default' => 'checks[0].difficulty.max: must be at least default (9), 7 given',
        'generated: v2-check-duplicate-key' => 'checks[1].key: duplicate check key "hack"',
        'generated: v2-check-step-unknown-check' => 'sceneTypes[0].play[0].check: unknown check "ghost"',
        'generated: v2-check-step-difficulty-without-declaration' => 'sceneTypes[0].play[0].difficulty: check "hack" declares no difficulty',
        'generated: v2-check-step-difficulty-below-min' => 'sceneTypes[0].play[0].difficulty: must be at least 6, the difficulty min of check "hack", 3 given',
        'generated: v2-check-step-difficulty-reads-sheet' => 'sceneTypes[0].play[0].difficulty.formula: {dex}: a flow formula reads trackers only',
        'generated: v2-check-step-difficulty-unknown-tracker' => 'sceneTypes[0].play[0].difficulty.tracker: unknown tracker "ghost"',
        'generated: v2-check-step-outcome-unknown-band' => 'sceneTypes[0].play[0].outcomes[0].band: unknown band "ghost" of check "hack"',
        'generated: v2-check-step-duplicate-outcome-band' => 'sceneTypes[0].play[0].outcomes[1].band: duplicate outcome band "miss"',
        'generated: v2-check-step-outcome-next-backward' => 'sceneTypes[0].play[0].outcomes[0].next: must name a later step of the same list or "end", "try" given',
        'generated: v2-check-step-outcome-unknown-tracker-effect' => 'sceneTypes[0].play[0].outcomes[0].effects[0].tracker: unknown tracker "ghost"',
        'generated: v2-sheet-effect-unknown-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].field: unknown sheet field "ghost"',
        'generated: v2-sheet-effect-derived-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].field: field "bonus" is a derived field, a sheet effect changes number and track fields only',
        'generated: v2-sheet-effect-text-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].field: field "name" is a text field, a sheet effect changes number and track fields only',
        'generated: v2-sheet-effect-list-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].field: field "gear" is a list field, a sheet effect changes number and track fields only',
        'generated: v2-sheet-effect-in-prompt-step' => 'sceneTypes[0].play[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-check-step-effects' => 'sceneTypes[0].play[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-roll-band' => 'sceneTypes[0].play[0].bands[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-choice-option' => 'sceneTypes[0].play[0].options[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-table-entry' => 'oracles.tables[0].entries[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-pick-branch' => 'sceneTypes[0].play[0].branches.found.effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-table-otherwise' => 'sceneTypes[0].play[0].otherwise.effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-condition-band' => 'sceneTypes[0].play[0].bands[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-in-scene-type-setup' => 'sceneTypes[0].setup[0].effects[0]: a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step',
        'generated: v2-sheet-effect-bad-dice' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.dice: invalid dice notation: ',
        'generated: v2-sheet-effect-formula-syntax' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.formula: ',
        'generated: v2-sheet-effect-formula-unknown-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.formula: {nope}: unknown field "nope"',
        'generated: v2-sheet-effect-formula-reads-check' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.formula: {check:difficulty}: a check reference is read only by the bands of a check',
        'generated: v2-sheet-effect-formula-unknown-tracker' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.formula: {tracker:ghost}: unknown tracker "ghost"',
        'generated: v2-sheet-effect-formula-reads-text-field' => 'sceneTypes[0].play[0].outcomes[0].effects[0].value.formula: {name}: field "name" is a text field',
        'chaos-not-ordered' => 'oracles.likelihood[0]: ',
        'duplicate-flow-step-key' => 'flow.steps[1].key: duplicate step key "begin"',
        'duplicate-key-across-tables-and-likelihood' => 'oracles.likelihood[0].key: duplicate oracle key "mood"',
        'duplicate-level-key' => 'oracles.likelihood[0]: ',
        'duplicate-likelihood-key' => 'oracles.likelihood[1].key: duplicate oracle key "fate"',
        'duplicate-table-key' => 'oracles.tables: ',
        'entry-without-text' => 'oracles.tables: ',
        'incomplete-range' => 'oracles.tables: ',
        'invalid-dice' => 'oracles.tables: ',
        'inverse-range' => 'oracles.tables: ',
        'level-target-above-sides' => 'oracles.likelihood[0]: ',
        'nested-cycle' => 'oracles.tables: ',
        'overlapping-ranges' => 'oracles.tables: ',
        'range-and-weight' => 'oracles.tables: ',
        'ranged-entry-in-weighted-table' => 'oracles.tables: ',
        'shift-per-point-above-sides' => 'oracles.likelihood[0]: ',
        'unknown-nested-table' => 'oracles.tables: ',
        'whitespace-only-game-system-name' => 'gameSystem.name: must not be blank',
        'whitespace-only-likelihood-name' => 'oracles.likelihood[0].name: must not be blank',
        'whitespace-only-step-title' => 'flow.steps[0].title: must not be blank',
        'v2-band-next-backward' => 'sceneTypes[0].play[1].bands[0].next: must name a later step of the same list or "end", "begin" given',
        'v2-band-up-to-not-increasing' => 'sceneTypes[0].play[1].bands[1].upTo: must be greater than 3',
        'v2-band-without-up-to' => 'sceneTypes[0].play[1].bands[0].upTo: required, only the last band omits it',
        'v2-chaos-tracker-clock' => 'oracles.likelihood[0].chaos.tracker: must be a counter, "alarm" is a clock',
        'v2-chaos-tracker-range' => 'oracles.likelihood[0].chaos.tracker: counter "chaos" must range 1..9 like the chaos factor, 0..10 given',
        'v2-chaos-tracker-unknown' => 'oracles.likelihood[0].chaos.tracker: unknown tracker "chaos"',
        'v2-duplicate-branch-entry' => 'sceneTypes[0].play[1].branches[1].entry: duplicate branch entry "patrol"',
        'v2-duplicate-entry-key' => 'oracles.tables[0].entries[1].key: duplicate entry key "patrol"',
        'v2-duplicate-fact-slot-key' => 'factSlots[1].key: duplicate fact slot key "city"',
        'v2-duplicate-flow-key' => 'flows[1].key: duplicate flow key "main"',
        'v2-duplicate-option-key' => 'sceneTypes[0].play[0].options[1].key: duplicate option key "a"',
        'v2-duplicate-phase-key' => 'flows[0].phases[1].key: duplicate phase key "only"',
        'v2-duplicate-scene-type-key' => 'sceneTypes[1].key: duplicate Scene Type key "scene"',
        'v2-duplicate-step-key' => 'sceneTypes[0].play[1].key: duplicate step key "begin"',
        'v2-duplicate-tracker-key' => 'trackers[1].key: duplicate tracker key "alarm"',
        'v2-inspiration-unknown-table' => 'sceneTypes[0].play[0].inspiration[1]: unknown oracle table "ghost"',
        'v2-inspiration-likelihood-oracle' => 'sceneTypes[0].play[0].inspiration[0]: unknown oracle table "fate"',
        'v2-initial-out-of-range' => 'trackers[0].initial: must be within min..max (0..3), 4 given',
        'v2-invalid-roll-dice' => 'sceneTypes[0].play[1].dice: invalid dice notation',
        'v2-last-band-with-up-to' => 'sceneTypes[0].play[1].bands[1].upTo: the last band catches the rest and must omit upTo',
        'v2-last-level-with-up-to' => 'trackers[0].levels[1].upTo: the last level catches the rest and must omit upTo',
        'v2-level-up-to-not-increasing' => 'trackers[0].levels[1].upTo: must be greater than 2, the previous upTo, 2 given',
        'v2-next-backward' => 'sceneTypes[0].play[1].next: must name a later step of the same list or "end", "begin" given',
        'v2-oracle-selection-entry-without-scene-type' => 'flows[0].phases[0].selection.table: every entry of table "scenes" must name a sceneType, oracles.tables[0].entries[1] does not',
        'v2-condition-count-unknown-tag' => 'sceneTypes[0].play[0].count.tags[0]: unknown tag "ghost"',
        'v2-random-event-sides' => 'oracles.likelihood[0]: A random event needs a likelihood oracle of 100 sides, 20 given.',
        'v2-random-event-unknown-table' => 'oracles.likelihood[0].randomEvent.table: unknown oracle table "ghost"',
        'v2-random-event-without-chaos' => 'oracles.likelihood[0]: A random event needs the chaos factor of a likelihood oracle.',
        'v2-credits-blank-notice' => 'gameSystem.credits.notice: must not be blank',
        'v2-duplicate-tag-key' => 'tags[2].key: duplicate tag key "ally"',
        'v2-effect-fill-fact-text-on-npc-slot' => 'sceneTypes[0].play[0].effects[0].text: only allowed for a text slot, fact slot "prince" is an npc slot',
        'v2-effect-fill-fact-unknown-slot' => 'sceneTypes[0].play[0].effects[0].slot: unknown fact slot "ghost"',
        'v2-effect-duplicate-tag' => 'sceneTypes[0].play[0].effects[0].tags[1]: duplicate tag "ally"',
        'v2-effect-unknown-tag' => 'sceneTypes[0].play[0].effects[0].tags[0]: unknown tag "ghost"',
        'v2-pick-count-min-above-max' => 'sceneTypes[0].play[0].count.max: must be at least min (3), 2 given',
        'v2-pick-duplicate-tag' => 'sceneTypes[0].play[0].tags[1]: duplicate tag "ally"',
        'v2-pick-next-backward' => 'sceneTypes[0].play[1].branches.found.next: must name a later step of the same list or "end", "begin" given',
        'v2-pick-tags-without-release-tags' => 'sceneTypes[0].play[0].tags[0]: unknown tag "ally"',
        'v2-pick-unknown-tag' => 'sceneTypes[0].play[0].tags[0]: unknown tag "ghost"',
        'v2-placeholder-picked-argument' => 'sceneTypes[0].play[0].title: placeholder {picked:x} is not supported in schema version 2',
        'v2-placeholder-answer-in-step' => 'sceneTypes[0].play[0].title: placeholder {answer} is only allowed in effect text',
        'v2-placeholder-unknown-step' => 'sceneTypes[0].play[0].tip: placeholder {step:nowhere}: unknown step "nowhere"',
        'v2-placeholder-unknown-tracker' => 'sceneTypes[0].play[0].title: placeholder {tracker:noise}: unknown tracker "noise"',
        'v2-placeholder-unsupported' => 'sceneTypes[0].play[0].prompt: placeholder {npc:city} is not supported in schema version 2',
        'v2-placeholder-unsupported-in-agenda' => 'sceneTypes[0].play[0].effects[0].agenda: placeholder {npc:city} is not supported in schema version 2',
        'v2-placeholder-unknown-fact' => 'sceneTypes[0].play[0].prompt: placeholder {fact:ghost}: unknown fact slot "ghost"',
        'v2-placeholder-unknown-fact-in-fill-fact' => 'sceneTypes[0].play[0].effects[0].text: placeholder {fact:ghost}: unknown fact slot "ghost"',
        'v2-placeholder-fact-without-key' => 'sceneTypes[0].play[0].prompt: placeholder {fact} needs a fact slot key, as in {fact:city}',
        'v2-scene-type-oracle-outside-flow' => 'sceneTypes[0].oracles[0]: oracle "fate" is not one of the oracles of flow "main"',
        'v2-sheet-duplicate-field-key' => 'sheet.fields[1].key: duplicate field key "luck"',
        'generated: v2-sheet-columns-without-groups' => 'sheet.columns: needs declared groups',
        'generated: v2-sheet-field-span-without-groups' => 'sheet.fields[0].span: needs declared groups',
        'generated: v2-sheet-duplicate-group-name' => 'sheet.groups[1].name: duplicate group name "Stats"',
        'generated: v2-sheet-group-name-blank' => 'sheet.groups[0].name: must not be blank',
        'generated: v2-sheet-group-span-above-columns' => 'sheet.groups[1].span: must be at most the columns of the sheet (1), 2 given',
        'generated: v2-sheet-field-span-above-group-columns' => 'sheet.fields[0].span: must be at most the columns of group "Stats" (2), 3 given',
        'generated: v2-sheet-field-without-group' => 'sheet.fields[0].group: required, the sheet declares groups',
        'generated: v2-sheet-field-in-undeclared-group' => 'sheet.fields[0].group: unknown group "Notes"',
        'v2-sheet-group-whitespace-only' => 'sheet.fields[0].group: must not be blank',
        'v2-sheet-item-label-whitespace-only' => 'sheet.fields[0].itemLabel: must not be blank',
        'v2-sheet-label-whitespace-only' => 'sheet.fields[0].label: must not be blank',
        'v2-sheet-number-default-above-max' => 'sheet.fields[0].default: must be within min..max, 4 is above max (3)',
        'v2-sheet-number-min-above-max' => 'sheet.fields[0].max: must be at least min (5), 2 given',
        'v2-skip-unknown-option' => 'sceneTypes[0].play[0].skip: unknown option "c"',
        'v2-suggested-choice-without-skip' => 'sceneTypes[0].play[0].skip: required, a suggested choice names the option a skip follows',
        'v2-tracker-outside-flow' => 'sceneTypes[0].play[0].effects[0].tracker: tracker "noise" is not one of the trackers of flow "main"',
        'v2-two-default-flows' => 'flows[1].default: at most one flow is the default, "main" already is',
        'v2-unknown-entry-scene-type' => 'oracles.tables[0].entries[0].sceneType: unknown Scene Type "dawn"',
        'v2-unknown-likelihood-level' => 'sceneTypes[0].play[1].likelihood: unknown level "certain" of likelihood oracle "fate"',
        'v2-unknown-oracle-in-step' => 'sceneTypes[0].play[1].oracle: unknown likelihood oracle "fate"',
        'v2-unknown-scene-type' => 'sceneTypes[0].play[0].effects[0].sceneType: unknown Scene Type "dawn"',
        'v2-unknown-scene-type-oracle' => 'sceneTypes[0].oracles[0]: unknown oracle "fate"',
        'v2-unknown-table-entry' => 'sceneTypes[0].play[1].branches[0].entry: unknown entry "riot" of table "events"',
        'v2-unknown-tracker-in-effect' => 'sceneTypes[0].play[0].effects[0].tracker: unknown tracker "noise"',
        'v2-whitespace-only-scene-type-name' => 'sceneTypes[0].name: must not be blank',
    ];

    /**
     * Valid fixtures, the flow example releases (docs/domain/flow-examples.md) and every shipped
     * preset, so a new example or preset is checked without listing it here.
     *
     * @return iterable<string, array{string}>
     */
    public static function validCases(): iterable
    {
        yield from self::fixtures('valid');
        foreach (self::generatedFormulaReleases() as $name => $release) {
            yield 'generated: '.$name => [json_encode($release, \JSON_THROW_ON_ERROR)];
        }

        foreach ([...self::generatedCheckCases()['valid'], ...self::generatedSheetLayoutCases()['valid']] as $name => $json) {
            yield $name => [$json];
        }

        foreach (self::fixtures('examples') as $name => $json) {
            yield 'example: '.$name => $json;
        }

        foreach (self::jsonFiles(self::PRESETS) as $name => $json) {
            yield 'preset: '.$name => $json;
        }
    }

    /**
     * @return iterable<string, array{string, string}> name => [name, JSON]
     */
    public static function structurallyInvalidCases(): iterable
    {
        foreach ([...self::fixtures('invalid/structural'), ...self::generatedStructuralCases()] as $name => [$json]) {
            yield $name => [$name, $json];
        }

        foreach ([...self::generatedCheckCases()['structural'], ...self::generatedSheetEffectCases()['structural'], ...self::generatedSheetLayoutCases()['structural']] as $name => $json) {
            yield $name => [$name, $json];
        }
    }

    /**
     * @return iterable<string, array{string, string}> name => [name, JSON]
     */
    public static function semanticallyInvalidCases(): iterable
    {
        foreach (self::fixtures('invalid/semantic') as $name => [$json]) {
            yield $name => [$name, $json];
        }

        foreach ([...self::generatedCheckCases()['semantic'], ...self::generatedSheetEffectCases()['semantic'], ...self::generatedSheetLayoutCases()['semantic']] as $name => $json) {
            yield $name => [$name, $json];
        }
    }

    #[Test]
    #[DataProvider('validCases')]
    public function validCasesPassTheSchemaAndTheDomain(string $json): void
    {
        self::assertSame([], $this->schemaErrorLocations($json));

        $content = ReleaseContent::fromArray($this->decodeRelease($json));

        self::assertSame([], $this->schemaErrorLocations(json_encode($content->toArray(), \JSON_THROW_ON_ERROR)), 'The canonical form conforms to the schema.');
    }

    #[Test]
    #[DataProvider('structurallyInvalidCases')]
    public function structuralInvalidCasesFailTheSchemaAndTheDomainForTheExpectedReason(string $name, string $json): void
    {
        self::assertArrayHasKey($name, self::STRUCTURAL, \sprintf('Add the expected reason of "%s" to STRUCTURAL.', $name));
        [$domainPrefix, $schemaLocation] = self::STRUCTURAL[$name];

        self::assertContains($schemaLocation, $this->schemaErrorLocations($json), 'The schema rejects it at the expected location.');
        self::assertStringStartsWith($domainPrefix, $this->domainError($json));
    }

    #[Test]
    #[DataProvider('semanticallyInvalidCases')]
    public function semanticInvalidCasesPassTheSchemaButFailTheDomainForTheExpectedReason(string $name, string $json): void
    {
        self::assertArrayHasKey($name, self::SEMANTIC, \sprintf('Add the expected reason of "%s" to SEMANTIC.', $name));

        self::assertSame([], $this->schemaErrorLocations($json), 'The schema cannot express this rule.');
        self::assertStringStartsWith(self::SEMANTIC[$name], $this->domainError($json));
    }

    #[Test]
    public function everyExpectedReasonHasACase(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::STRUCTURAL), array_keys([...self::structurallyInvalidCases()]))));
        self::assertSame([], array_values(array_diff(array_keys(self::SEMANTIC), array_keys([...self::semanticallyInvalidCases()]))));
    }

    /**
     * Cases that only exceed a size limit, built in code to keep the fixtures small.
     *
     * @return array<string, array{string}>
     */
    private static function generatedStructuralCases(): array
    {
        $table = static fn (int $i): array => ['key' => 't-'.$i, 'name' => 'Table', 'entries' => [['text' => 'x']]];
        $likelihood = static fn (int $i): array => ['key' => 'l-'.$i, 'name' => 'Fate', 'sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]]];
        $step = static fn (int $i): array => ['key' => 's-'.$i, 'title' => 'Step'];

        $generated = [
            'too-many-tables' => ['oracles' => ['tables' => array_map($table, range(1, 51)), 'likelihood' => []]],
            'too-many-likelihood-oracles' => ['oracles' => ['tables' => [], 'likelihood' => array_map($likelihood, range(1, 21))]],
            'too-many-flow-steps' => ['flow' => ['steps' => array_map($step, range(1, 101))]],
            'too-long-game-system-key' => ['gameSystem' => ['key' => str_repeat('a', 65), 'name' => 'Minimal']],
            'too-long-game-system-name' => ['gameSystem' => ['key' => 'minimal', 'name' => str_repeat('n', 101)]],
            'too-long-description' => ['gameSystem' => ['key' => 'minimal', 'name' => 'Minimal', 'description' => str_repeat('d', 2001)]],
            'too-long-likelihood-name' => ['oracles' => ['tables' => [], 'likelihood' => [['name' => str_repeat('n', 501)] + $likelihood(1)]]],
            'too-long-step-title' => ['flow' => ['steps' => [['key' => 'begin', 'title' => str_repeat('t', 101)]]]],
            'too-long-step-prompt' => ['flow' => ['steps' => [['key' => 'begin', 'title' => 'Begin', 'prompt' => str_repeat('p', 2001)]]]],
            'entry-text-too-long' => ['oracles' => ['tables' => [['key' => 'mood', 'name' => 'Mood', 'entries' => [['text' => str_repeat('t', 501)]]]], 'likelihood' => []]],
        ];

        $cases = [];
        foreach ($generated as $name => $overrides) {
            $cases['generated: '.$name] = [json_encode([...self::minimalRelease(), ...$overrides], \JSON_THROW_ON_ERROR)];
        }

        $clock = static fn (int $i): array => ['key' => 't-'.$i, 'name' => 'Tracker', 'kind' => 'clock', 'segments' => 4];
        $level = static fn (int $i): array => ['upTo' => $i, 'label' => 'Level'];
        $slot = static fn (int $i): array => ['key' => 's-'.$i, 'label' => 'Slot', 'type' => 'text'];
        $tag = static fn (int $i): array => ['key' => 't-'.$i, 'label' => 'Tag'];
        $counter = ['key' => 'edge', 'name' => 'Edge', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0];
        $prompt = static fn (int $i): array => ['key' => 's-'.$i, 'kind' => 'prompt', 'title' => 'Step'];
        $sceneType = static fn (array $play): array => [['key' => 'scene', 'name' => 'Scene', 'purpose' => 'Play a scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []]];
        $flow = static fn (int $i): array => [
            'key' => 'f-'.$i, 'name' => 'Flow', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [],
            'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]]],
        ];

        $generatedV2 = [
            'v2-too-many-trackers' => ['trackers' => array_map($clock, range(1, 51))],
            'v2-too-many-fact-slots' => ['factSlots' => array_map($slot, range(1, 101))],
            'v2-too-many-levels' => ['trackers' => [['levels' => [...array_map($level, range(1, 20)), ['label' => 'Rest']]] + $counter]],
            'v2-too-long-fact-slot-hint' => ['factSlots' => [['hint' => str_repeat('h', 501)] + $slot(1)]],
            'v2-too-long-tracker-hint' => ['trackers' => [['hint' => str_repeat('h', 501)] + $clock(1)]],
            'v2-too-many-steps' => ['sceneTypes' => $sceneType(array_map($prompt, range(1, 51)))],
            'v2-too-many-effects' => ['sceneTypes' => $sceneType([['effects' => array_fill(0, 11, ['kind' => 'endPhase'])] + $prompt(1)])],
            'v2-too-long-step-tip' => ['sceneTypes' => $sceneType([['tip' => str_repeat('t', 2001)] + $prompt(1)])],
            'v2-too-many-tags' => ['tags' => array_map($tag, range(1, 51))],
            'v2-too-long-tag-hint' => ['tags' => [['hint' => str_repeat('h', 501)] + $tag(1)]],
            'v2-tag-applies-to-empty' => ['tags' => [['appliesTo' => []] + $tag(1)]],
            'v2-tag-applies-to-unknown-kind' => ['tags' => [['appliesTo' => ['npc', 'faction']] + $tag(1)]],
            'v2-tag-applies-to-duplicate-kind' => ['tags' => [['appliesTo' => ['npc', 'npc']] + $tag(1)]],
            'v2-too-long-npc-agenda' => ['sceneTypes' => $sceneType([['effects' => [['kind' => 'createNpc', 'agenda' => str_repeat('a', 2001)]]] + $prompt(1)])],
            'v2-sheet-too-many-fields' => ['sheet' => ['fields' => array_map(static fn (int $i): array => ['key' => 'f-'.$i, 'label' => 'Field', 'type' => 'number'], range(1, 101))]],
            'v2-sheet-label-too-long' => ['sheet' => ['fields' => [['key' => 'luck', 'label' => str_repeat('l', 101), 'type' => 'number']]]],
            'v2-too-long-fill-fact-text' => ['factSlots' => [['key' => 'city', 'label' => 'City', 'type' => 'text']], 'sceneTypes' => $sceneType([['effects' => [['kind' => 'fillFact', 'slot' => 'city', 'text' => str_repeat('t', 2001)]]] + $prompt(1)])],
            'v2-too-long-thread-name' => ['sceneTypes' => $sceneType([['effects' => [['kind' => 'createThread', 'name' => str_repeat('n', 201)]]] + $prompt(1)])],
            'v2-too-many-flows' => ['sceneTypes' => $sceneType([]), 'flows' => array_map($flow, range(1, 21))],
            'v2-too-long-credits-notice' => ['gameSystem' => ['key' => 'minimal', 'name' => 'Minimal', 'credits' => ['notice' => str_repeat('n', 2001)]]],
            'v2-too-long-credits-license' => ['gameSystem' => ['key' => 'minimal', 'name' => 'Minimal', 'credits' => ['notice' => 'Fan.', 'license' => str_repeat('l', 201)]]],
            'v2-too-long-credits-url' => ['gameSystem' => ['key' => 'minimal', 'name' => 'Minimal', 'credits' => ['notice' => 'Fan.', 'url' => 'https://example.com/'.str_repeat('u', 481)]]],
            'v2-too-long-introduction' => ['sceneTypes' => $sceneType([]), 'flows' => [['introduction' => str_repeat('i', 5001)] + $flow(1)]],
            'v2-too-long-phase-description' => ['sceneTypes' => $sceneType([]), 'flows' => [['phases' => [['description' => str_repeat('d', 2001)] + $flow(1)['phases'][0]]] + $flow(1)]],
        ];

        $alarm = ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6];
        /** @param array<string, mixed> $extra */
        $derived = static fn (mixed $formula, array $extra = []): array => self::derivedSheet([['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => $formula] + $extra]);
        $upTo = static fn (mixed $upTo): array => ['trackers' => [$alarm], 'sceneTypes' => $sceneType([['key' => 'check', 'kind' => 'condition', 'title' => 'Check', 'tracker' => 'alarm', 'bands' => [['upTo' => $upTo], new \stdClass()]]])];
        $label = static fn (mixed $label): array => ['trackers' => [$alarm], 'sceneTypes' => $sceneType([['key' => 'check', 'kind' => 'condition', 'title' => 'Check', 'tracker' => 'alarm', 'bands' => [['upTo' => 1, 'label' => $label], new \stdClass()]]])];
        $value = static fn (mixed $value): array => ['trackers' => [$alarm], 'sceneTypes' => $sceneType([['effects' => [['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => $value]]] + $prompt(1)])];
        $generatedV2 += [
            'v2-derived-without-formula' => self::derivedSheet([['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived']]),
            'v2-derived-empty-formula' => $derived(''),
            'v2-derived-formula-too-long' => $derived(str_repeat('1', 201)),
            'v2-derived-formula-not-a-string' => $derived(3),
            'v2-derived-with-min' => $derived('1', ['min' => 0]),
            'v2-band-formula-with-extra-property' => $upTo(['formula' => '1', 'plus' => 1]),
            'v2-band-formula-empty' => $upTo(['formula' => '']),
            'v2-band-label-empty' => $label(''),
            'v2-band-label-blank' => $label('   '),
            'v2-band-label-too-long' => $label(str_repeat('l', 81)),
            'v2-band-label-not-a-string' => $label(7),
            'v2-effect-formula-with-extra-property' => $value(['formula' => '1', 'plus' => 1]),
            'v2-effect-formula-too-long' => $value(['formula' => str_repeat('1', 201)]),
            'v2-counter-level-formula' => ['trackers' => [['levels' => [['upTo' => ['formula' => '1'], 'label' => 'Low'], ['label' => 'Rest']]] + $counter]],
            'v2-counter-level-tracker' => ['trackers' => [['levels' => [['upTo' => ['tracker' => 'edge'], 'label' => 'Low'], ['label' => 'Rest']]] + $counter]],
        ];
        foreach ($generatedV2 as $name => $overrides) {
            $cases['generated: '.$name] = [json_encode([...self::minimalReleaseV2(), ...$overrides], \JSON_THROW_ON_ERROR)];
        }

        return $cases;
    }

    /**
     * Releases with checks and check steps, built in code from one valid base (a Hack check, 1d10 plus
     * Dexterity against a Difficulty, and a step rolling it): the valid ones, the ones the schema rejects,
     * and the ones only the domain rejects.
     *
     * @return array{valid: array<string, string>, structural: array<string, string>, semantic: array<string, string>} JSON by case name
     */
    private static function generatedCheckCases(): array
    {
        $difficulty = ['label' => 'DV', 'default' => 9, 'min' => 6, 'max' => 29];
        $bands = [['key' => 'miss', 'label' => 'Miss', 'upTo' => ['formula' => '{check:difficulty}-1']], ['key' => 'hit', 'label' => 'Hit']];
        $check = ['key' => 'hack', 'label' => 'Hack', 'dice' => '1d10', 'modifier' => '{dex}', 'difficulty' => $difficulty, 'bands' => $bands];
        $stepOf = static fn (array $step): array => ['key' => 'try', 'kind' => 'check', 'title' => 'Try', 'check' => 'hack', ...$step];
        $build = static fn (array $checks, array $step = [], string $omit = ''): string => json_encode([
            ...self::minimalReleaseV2(),
            'trackers' => [['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6]],
            'sheet' => ['fields' => [['key' => 'dex', 'label' => 'Dexterity', 'type' => 'number'], ['key' => 'name', 'label' => 'Name', 'type' => 'text']]],
            'sceneTypes' => [['key' => 'scene', 'name' => 'Scene', 'purpose' => 'Play a scene.', 'oracles' => [], 'setup' => [], 'play' => [array_diff_key($stepOf($step), [$omit => true])], 'closing' => []]],
            'checks' => $checks,
        ], \JSON_THROW_ON_ERROR);
        /** @param array<string, mixed> $override */
        $with = static fn (array $override, array $step = []): string => $build([[...$check, ...$override]], $step);
        $band = static fn (int $i): array => ['key' => 'b-'.$i, 'label' => 'Band', 'upTo' => $i];
        $trackerEffect = static fn (string $tracker): array => ['kind' => 'tracker', 'tracker' => $tracker, 'op' => 'add', 'value' => 1];

        $valid = [
            'v2-checks' => $build([$check, ['key' => 'sneak', 'label' => 'Sneak', 'hint' => 'Quietly.', 'dice' => '2d6kh1', 'bands' => [['key' => 'low', 'label' => 'Low', 'upTo' => ['tracker' => 'alarm']], ['key' => 'high', 'label' => 'High']]]], [
                'difficulty' => ['formula' => '{tracker:alarm}+6'],
                'outcomes' => [['band' => 'miss', 'next' => 'end', 'effects' => [$trackerEffect('alarm')]], ['band' => 'hit']],
            ]),
            'v2-check-minimal' => $build([['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'bands' => [['key' => 'only', 'label' => 'Only']]]], ['check' => 'luck']),
            'v2-check-nulls' => $with(['hint' => null, 'modifier' => null, 'difficulty' => ['label' => 'DV', 'default' => 9, 'min' => null, 'max' => null], 'bands' => [['key' => 'only', 'label' => 'Only', 'upTo' => null]]], ['difficulty' => 40, 'outcomes' => null]),
        ];

        $structural = [
            'v2-check-unknown-property' => $with(['roll' => 'x']),
            'v2-check-missing-dice' => $build([['key' => 'hack', 'label' => 'Hack', 'bands' => $bands]]),
            'v2-check-no-bands' => $with(['bands' => []]),
            'v2-check-too-many-bands' => $with(['bands' => [...array_map($band, range(1, 10)), ['key' => 'rest', 'label' => 'Rest']]]),
            'v2-too-many-checks' => $build(array_map(static fn (int $i): array => [...$check, 'key' => 'c-'.$i], range(1, 51))),
            'v2-check-dice-too-long' => $with(['dice' => str_repeat('1', 101)]),
            'v2-check-modifier-too-long' => $with(['modifier' => str_repeat('1', 201)]),
            'v2-check-hint-too-long' => $with(['hint' => str_repeat('h', 501)]),
            'v2-check-label-empty' => $with(['label' => '']),
            'v2-check-bad-key' => $with(['key' => 'Hack!']),
            'v2-check-difficulty-default-out-of-range' => $with(['difficulty' => [...$difficulty, 'max' => 1000, 'default' => 1001]]),
            'v2-check-difficulty-missing-default' => $with(['difficulty' => ['label' => 'DV']]),
            'v2-check-difficulty-label-empty' => $with(['difficulty' => [...$difficulty, 'label' => '']]),
            'v2-check-difficulty-unknown-property' => $with(['difficulty' => [...$difficulty, 'step' => 1]]),
            'v2-check-band-up-to-string' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 'five'], ['key' => 'hit', 'label' => 'Hit']]]),
            'v2-check-band-missing-key' => $with(['bands' => [['label' => 'Miss', 'upTo' => 5], ['key' => 'hit', 'label' => 'Hit']]]),
            'v2-check-band-unknown-property' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 5, 'next' => 'end'], ['key' => 'hit', 'label' => 'Hit']]]),
            'v2-check-band-formula-empty' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => ['formula' => '']], ['key' => 'hit', 'label' => 'Hit']]]),
            'v2-check-step-missing-check' => $build([$check], omit: 'check'),
            'v2-check-step-unknown-property' => $with([], ['bands' => []]),
            'v2-check-step-difficulty-string' => $with([], ['difficulty' => 'hard']),
            'v2-check-step-outcome-missing-band' => $with([], ['outcomes' => [['next' => 'end']]]),
            'v2-check-step-outcome-unknown-property' => $with([], ['outcomes' => [['band' => 'miss', 'prompt' => 'x']]]),
            'v2-check-step-too-many-outcomes' => $with([], ['outcomes' => array_fill(0, 11, ['band' => 'miss'])]),
        ];

        $upTo = static fn (mixed $upTo): array => ['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => $upTo], ['key' => 'hit', 'label' => 'Hit']]];
        $twoUpTo = static fn (int $first, int $second): array => ['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => $first], ['key' => 'hit', 'label' => 'Hit', 'upTo' => $second], ['key' => 'crit', 'label' => 'Crit']]];
        $semantic = [
            'v2-check-invalid-dice' => $with(['dice' => 'banana']),
            'v2-check-modifier-syntax' => $with(['modifier' => '1 +']),
            'v2-check-modifier-unknown-field' => $with(['modifier' => '{nope}']),
            'v2-check-modifier-reads-check' => $with(['modifier' => '{check:difficulty}']),
            'v2-check-modifier-reads-text-field' => $with(['modifier' => '{name}']),
            'v2-check-band-reads-undeclared-difficulty' => $with(['difficulty' => null]),
            'v2-check-band-reads-other-check-value' => $with($upTo(['formula' => '{check:target}'])),
            'v2-check-band-formula-unknown-field' => $with($upTo(['formula' => '{nope}'])),
            'v2-check-band-unknown-tracker' => $with($upTo(['tracker' => 'ghost'])),
            'v2-check-duplicate-band-key' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 5], ['key' => 'miss', 'label' => 'Again']]]),
            'v2-check-band-without-up-to' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss'], ['key' => 'hit', 'label' => 'Hit']]]),
            'v2-check-last-band-with-up-to' => $with(['bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 5], ['key' => 'hit', 'label' => 'Hit', 'upTo' => 9]]]),
            'v2-check-bands-not-increasing' => $with($twoUpTo(5, 5)),
            'v2-check-difficulty-min-above-default' => $with(['difficulty' => [...$difficulty, 'min' => 12]]),
            'v2-check-difficulty-max-below-default' => $with(['difficulty' => [...$difficulty, 'max' => 7]]),
            'v2-check-duplicate-key' => $build([$check, $check]),
            'v2-check-step-unknown-check' => $with([], ['check' => 'ghost']),
            'v2-check-step-difficulty-without-declaration' => $with($upTo(5) + ['difficulty' => null], ['difficulty' => 5]),
            'v2-check-step-difficulty-below-min' => $with([], ['difficulty' => 3]),
            'v2-check-step-difficulty-reads-sheet' => $with([], ['difficulty' => ['formula' => '{dex}']]),
            'v2-check-step-difficulty-unknown-tracker' => $with([], ['difficulty' => ['tracker' => 'ghost']]),
            'v2-check-step-outcome-unknown-band' => $with([], ['outcomes' => [['band' => 'ghost']]]),
            'v2-check-step-duplicate-outcome-band' => $with([], ['outcomes' => [['band' => 'miss'], ['band' => 'miss']]]),
            'v2-check-step-outcome-next-backward' => $with([], ['outcomes' => [['band' => 'miss', 'next' => 'try']]]),
            'v2-check-step-outcome-unknown-tracker-effect' => $with([], ['outcomes' => [['band' => 'miss', 'effects' => [$trackerEffect('ghost')]]]]),
        ];

        return ['valid' => self::generated($valid), 'structural' => self::generated($structural), 'semantic' => self::generated($semantic)];
    }

    /**
     * Sheet effects (ADR 0027): the shape of the effect and its value forms, and where it may sit.
     *
     * @return array{structural: array<string, string>, semantic: array<string, string>}
     */
    private static function generatedSheetEffectCases(): array
    {
        // A null override leaves the property out.
        $effect = static fn (array $override = []): array => array_filter(['kind' => 'sheet', 'field' => 'sanity', 'op' => 'subtract', 'value' => 1, ...$override], static fn (mixed $property): bool => null !== $property);
        $check = ['key' => 'sanity', 'label' => 'Sanity check', 'dice' => 'd%', 'bands' => [['key' => 'fail', 'label' => 'Fail']]];
        $stepOf = static fn (array $step): array => ['key' => 'try', 'kind' => 'prompt', 'title' => 'Try', ...$step];
        $build = static fn (array $play, array $tables = [], array $setup = []): string => json_encode([
            ...self::minimalReleaseV2(),
            'oracles' => ['tables' => $tables, 'likelihood' => []],
            'trackers' => [['key' => 'dread', 'name' => 'Dread', 'kind' => 'clock', 'segments' => 6]],
            'sheet' => ['fields' => [
                ['key' => 'pow', 'label' => 'Power', 'type' => 'number'],
                ['key' => 'sanity', 'label' => 'Sanity', 'type' => 'track', 'max' => 99],
                ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '{pow}/10'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'gear', 'label' => 'Gear', 'type' => 'list'],
            ]],
            'sceneTypes' => [['key' => 'scene', 'name' => 'Scene', 'purpose' => 'Play a scene.', 'oracles' => [], 'setup' => $setup, 'play' => $play, 'closing' => []]],
            'checks' => [$check],
        ], \JSON_THROW_ON_ERROR);
        // The effect sits in the first outcome of a check step, a place where it is allowed.
        $inOutcome = static fn (array $override = []): string => $build([$stepOf(['kind' => 'check', 'check' => 'sanity', 'outcomes' => [['band' => 'fail', 'effects' => [$effect($override)]]]])]);

        $structural = [
            'v2-sheet-effect-bad-op' => $inOutcome(['op' => 'multiply']),
            'v2-sheet-effect-unknown-property' => $inOutcome(['tracker' => 'dread']),
            'v2-sheet-effect-missing-field' => $inOutcome(['field' => null]),
            'v2-sheet-effect-value-out-of-range' => $inOutcome(['value' => 1001]),
            'v2-sheet-effect-dice-too-long' => $inOutcome(['value' => ['dice' => str_repeat('1', 101)]]),
            'v2-sheet-effect-dice-not-a-string' => $inOutcome(['value' => ['dice' => 6]]),
            'v2-sheet-effect-value-formula-and-dice' => $inOutcome(['value' => ['formula' => '1', 'dice' => '1d6']]),
        ];

        $semantic = [
            'v2-sheet-effect-unknown-field' => $inOutcome(['field' => 'ghost']),
            'v2-sheet-effect-derived-field' => $inOutcome(['field' => 'bonus']),
            'v2-sheet-effect-text-field' => $inOutcome(['field' => 'name']),
            'v2-sheet-effect-list-field' => $inOutcome(['field' => 'gear']),
            'v2-sheet-effect-in-prompt-step' => $build([$stepOf(['effects' => [$effect()]])]),
            'v2-sheet-effect-in-check-step-effects' => $build([$stepOf(['kind' => 'check', 'check' => 'sanity', 'effects' => [$effect()]])]),
            'v2-sheet-effect-in-roll-band' => $build([$stepOf(['kind' => 'roll', 'dice' => '1d6', 'bands' => [['effects' => [$effect()]]]])]),
            'v2-sheet-effect-in-choice-option' => $build([$stepOf(['kind' => 'choice', 'mandatory' => true, 'options' => [['key' => 'a', 'label' => 'A', 'effects' => [$effect()]], ['key' => 'b', 'label' => 'B']]])]),
            'v2-sheet-effect-in-table-entry' => $build([], [['key' => 'omens', 'name' => 'Omens', 'entries' => [['text' => 'A crow', 'effects' => [$effect()]]]]]),
            'v2-sheet-effect-in-pick-branch' => $build([$stepOf(['kind' => 'pick', 'of' => 'npc', 'mode' => 'random', 'branches' => ['found' => ['effects' => [$effect()]]]])]),
            'v2-sheet-effect-in-table-otherwise' => $build([$stepOf(['kind' => 'table', 'table' => 'omens', 'otherwise' => ['effects' => [$effect()]]])], [['key' => 'omens', 'name' => 'Omens', 'entries' => [['text' => 'A crow']]]]),
            'v2-sheet-effect-in-condition-band' => $build([$stepOf(['kind' => 'condition', 'tracker' => 'dread', 'bands' => [['effects' => [$effect()]]]])]),
            'v2-sheet-effect-in-scene-type-setup' => $build([], [], [$stepOf(['effects' => [$effect()]])]),
            'v2-sheet-effect-bad-dice' => $inOutcome(['value' => ['dice' => 'banana']]),
            'v2-sheet-effect-formula-syntax' => $inOutcome(['value' => ['formula' => '1 +']]),
            'v2-sheet-effect-formula-unknown-field' => $inOutcome(['value' => ['formula' => '{nope}']]),
            'v2-sheet-effect-formula-reads-check' => $inOutcome(['value' => ['formula' => '{check:difficulty}']]),
            'v2-sheet-effect-formula-unknown-tracker' => $inOutcome(['value' => ['formula' => '{tracker:ghost}']]),
            'v2-sheet-effect-formula-reads-text-field' => $inOutcome(['value' => ['formula' => '{name}']]),
        ];

        return ['structural' => self::generated($structural), 'semantic' => self::generated($semantic)];
    }

    /**
     * Releases with a sheet layout (ADR 0031), built in code from the minimal release: the valid ones,
     * the ones the schema rejects, and the ones only the domain rejects.
     *
     * @return array{valid: array<string, string>, structural: array<string, string>, semantic: array<string, string>} JSON by case name
     */
    private static function generatedSheetLayoutCases(): array
    {
        $field = static fn (array $override = []): array => ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => 'Stats', ...$override];
        $build = static fn (array $sheet): string => json_encode([...self::minimalReleaseV2(), 'sheet' => $sheet], \JSON_THROW_ON_ERROR);
        $stats = [['name' => 'Stats', 'columns' => 2]];
        $names = static fn (int $count): array => array_map(static fn (int $i): array => ['name' => 'G'.$i], range(1, $count));

        $valid = [
            'v2-sheet-layout' => $build(['columns' => 2, 'groups' => [['name' => 'Stats', 'columns' => 4, 'span' => 2], ['name' => 'Notes']], 'fields' => [$field(['span' => 4]), $field(['key' => 'bio', 'group' => 'Notes', 'span' => 1])]]),
            'v2-sheet-layout-defaults-and-nulls' => $build(['columns' => null, 'groups' => [['name' => 'Stats', 'columns' => 1, 'span' => null]], 'fields' => [$field(['span' => null])]]),
            'v2-sheet-layout-empty-group' => $build(['groups' => [['name' => 'Stats'], ['name' => 'Empty']], 'fields' => [$field()]]),
            'v2-sheet-layout-20-groups' => $build(['groups' => $names(20)]),
            'v2-sheet-layout-null-groups' => $build(['groups' => null, 'fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number']]]),
        ];

        $structural = [
            'v2-sheet-columns-0' => $build(['columns' => 0, 'groups' => $stats]),
            'v2-sheet-columns-3' => $build(['columns' => 3, 'groups' => $stats]),
            'v2-sheet-columns-string' => $build(['columns' => '2', 'groups' => $stats]),
            'v2-sheet-groups-object' => $build(['groups' => ['name' => 'Stats']]),
            'v2-sheet-21-groups' => $build(['groups' => $names(21)]),
            'v2-sheet-group-without-name' => $build(['groups' => [['columns' => 2]]]),
            'v2-sheet-group-name-empty' => $build(['groups' => [['name' => '']]]),
            'v2-sheet-group-name-61' => $build(['groups' => [['name' => str_repeat('n', 61)]]]),
            'v2-sheet-group-columns-5' => $build(['groups' => [['name' => 'Stats', 'columns' => 5]]]),
            'v2-sheet-group-span-3' => $build(['groups' => [['name' => 'Stats', 'span' => 3]]]),
            'v2-sheet-group-span-string' => $build(['groups' => [['name' => 'Stats', 'span' => '2']]]),
            'v2-sheet-group-unknown-property' => $build(['groups' => [['name' => 'Stats', 'color' => 'red']]]),
            'v2-sheet-field-span-0' => $build(['groups' => $stats, 'fields' => [$field(['span' => 0])]]),
            'v2-sheet-field-span-5' => $build(['groups' => $stats, 'fields' => [$field(['span' => 5])]]),
        ];

        $semantic = [
            'v2-sheet-columns-without-groups' => $build(['columns' => 2]),
            'v2-sheet-field-span-without-groups' => $build(['fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'span' => 1]]]),
            'v2-sheet-duplicate-group-name' => $build(['groups' => [['name' => 'Stats'], ['name' => 'Stats']]]),
            'v2-sheet-group-name-blank' => $build(['groups' => [['name' => '  ']]]),
            'v2-sheet-group-span-above-columns' => $build(['groups' => [['name' => 'Stats'], ['name' => 'Notes', 'span' => 2]]]),
            'v2-sheet-field-span-above-group-columns' => $build(['groups' => $stats, 'fields' => [$field(['span' => 3])]]),
            'v2-sheet-field-without-group' => $build(['groups' => $stats, 'fields' => [$field(['group' => null])]]),
            'v2-sheet-field-in-undeclared-group' => $build(['groups' => $stats, 'fields' => [$field(['group' => 'Notes'])]]),
        ];

        return ['valid' => self::generated($valid), 'structural' => self::generated($structural), 'semantic' => self::generated($semantic)];
    }

    /**
     * @param array<string, string> $cases
     *
     * @return array<string, string> the cases under their "generated: " names
     */
    private static function generated(array $cases): array
    {
        $named = [];
        foreach ($cases as $name => $json) {
            $named['generated: '.$name] = $json;
        }

        return $named;
    }

    /**
     * Valid releases with formulas, built in code: a derived field, a formula band bound and a formula effect value.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function generatedFormulaReleases(): array
    {
        $alarm = ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6];
        $scene = static fn (array $play): array => [['key' => 'scene', 'name' => 'Scene', 'purpose' => 'Play a scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []]];

        return [
            'v2-derived-fields' => [...self::minimalReleaseV2(), ...self::derivedSheet([
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number'],
                ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track'],
                ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '({str}-10)/2', 'hint' => 'Modifier', 'group' => 'Derived'],
                ['key' => 'reserve', 'label' => 'Reserve', 'type' => 'derived', 'formula' => 'max({bonus},{hp.max})+{tracker:alarm}', 'hint' => null, 'group' => null],
            ]), 'trackers' => [$alarm]],
            'v2-formula-band-bound' => [...self::minimalReleaseV2(), 'trackers' => [$alarm], 'sceneTypes' => $scene([
                ['key' => 'check', 'kind' => 'condition', 'title' => 'Check', 'tracker' => 'alarm', 'bands' => [['upTo' => ['formula' => '{tracker:alarm}*2']], ['upTo' => 9], new \stdClass()]],
            ])],
            'v2-formula-effect-value' => [...self::minimalReleaseV2(), 'trackers' => [$alarm], 'sceneTypes' => $scene([
                ['key' => 'begin', 'kind' => 'prompt', 'title' => 'Begin', 'effects' => [['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'set', 'value' => ['formula' => 'min({tracker:alarm},3)']]]],
            ])],
        ];
    }

    /**
     * @param list<array<mixed>> $fields
     *
     * @return array{sheet: array{fields: list<array<mixed>>}}
     */
    private static function derivedSheet(array $fields): array
    {
        return ['sheet' => ['fields' => $fields]];
    }

    /**
     * The valid fixture v2-minimal.json.
     *
     * @return array<string, mixed>
     */
    private static function minimalReleaseV2(): array
    {
        $json = file_get_contents(self::FIXTURES.'/valid/v2-minimal.json');
        self::assertIsString($json);

        /** @var array<string, mixed> $release */
        $release = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $release['sheet'] = new \stdClass();

        return $release;
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimalRelease(): array
    {
        return [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => 'minimal', 'name' => 'Minimal'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => new \stdClass(),
            'checks' => [],
        ];
    }

    /**
     * @return array<string, array{string}> fixture contents by file name
     */
    private static function fixtures(string $directory): array
    {
        return self::jsonFiles(self::FIXTURES.'/'.$directory);
    }

    /**
     * @return array<string, array{string}> file contents by file name
     */
    private static function jsonFiles(string $directory): array
    {
        $files = glob($directory.'/*.json') ?: [];
        self::assertNotSame([], $files, 'No JSON files in '.$directory);

        $fixtures = [];
        foreach ($files as $file) {
            $json = file_get_contents($file);
            self::assertIsString($json, 'Cannot read '.$file);
            $fixtures[basename($file, '.json')] = [$json];
        }

        return $fixtures;
    }

    /**
     * @return array<mixed>
     */
    private function decodeRelease(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data, 'Not a JSON object or list.');

        return $data;
    }

    private function domainError(string $json): string
    {
        try {
            ReleaseContent::fromArray($this->decodeRelease($json));
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            return $invalidReleaseContent->getMessage();
        }

        self::fail('The domain accepts it.');
    }

    /**
     * @return list<string> the JSON pointers of the innermost schema errors, empty when valid
     */
    private function schemaErrorLocations(string $json): array
    {
        $validator = new Validator();
        $validator->setMaxErrors(5);
        foreach (self::SCHEMA_VERSIONS as $version) {
            $validator->resolver()?->registerFile(\sprintf(self::SCHEMA_ID, $version), \sprintf(self::SCHEMA_FILE, $version));
        }

        $data = json_decode($json, flags: \JSON_THROW_ON_ERROR);
        $version = $data instanceof \stdClass && \in_array($data->schemaVersion ?? null, self::SCHEMA_VERSIONS, true) ? $data->schemaVersion : 1;
        $error = $validator->validate($data, \sprintf(self::SCHEMA_ID, $version))->error();

        return $error instanceof ValidationError ? array_values(array_unique(self::leafLocations($error))) : [];
    }

    /**
     * @return list<string>
     */
    private static function leafLocations(ValidationError $error): array
    {
        $locations = [];
        foreach ($error->subErrors() as $subError) {
            if ($subError instanceof ValidationError) {
                $locations = [...$locations, ...self::leafLocations($subError)];
            }
        }

        return [] === $locations ? ['/'.implode('/', $error->data()->fullPath())] : $locations;
    }
}
