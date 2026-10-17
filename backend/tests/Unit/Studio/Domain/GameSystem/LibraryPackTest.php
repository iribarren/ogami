<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemKind;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\ReleaseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystem::class)]
#[CoversClass(GameSystemKind::class)]
#[CoversClass(LibraryPackRefuses::class)]
final class LibraryPackTest extends TestCase
{
    private const string ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61';

    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-18T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-18T11:00:00');
    }

    private function pack(): GameSystem
    {
        return GameSystem::createLibraryPack(GameSystemId::fromString(self::ID), 'fantasy-names', 'user-1', 'Fantasy names', 'Generic tables', $this->created);
    }

    #[Test]
    public function aPackIsABlankSchemaVersion2DocumentWithoutThemeOrCredits(): void
    {
        $pack = $this->pack();
        $blank = GameSystem::createBlank(GameSystemId::fromString(self::ID), 'fantasy-names', 'Fantasy names', 'Generic tables', 'user-1', $this->created);

        self::assertSame(GameSystemKind::LibraryPack, $pack->kind());
        self::assertTrue($pack->isLibraryPack());
        self::assertSame(GameSystemKind::GameSystem, $blank->kind());
        self::assertFalse($blank->isLibraryPack());
        self::assertSame($blank->document(), $pack->document());
        self::assertSame(2, $pack->schemaVersion());
        self::assertSame(['key' => 'fantasy-names', 'name' => 'Fantasy names', 'description' => 'Generic tables'], $pack->document()['gameSystem']);
        self::assertArrayNotHasKey('theme', $pack->document());
        self::assertSame([], $pack->libraryImports());
        self::assertTrue($pack->isOwnedBy('user-1'));
    }

    #[Test]
    public function aPackFollowsTheDraftKeyAndMetadataRules(): void
    {
        $this->expectException(InvalidGameSystemMetadata::class);

        GameSystem::createLibraryPack(GameSystemId::fromString(self::ID), 'Not A Key', 'user-1', 'Names', null, $this->created);
    }

    /**
     * @return iterable<string, array{\Closure(GameSystem, \DateTimeImmutable): void, string}>
     */
    public static function refusedOperations(): iterable
    {
        $field = ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'];
        $check = ['key' => 'grit', 'name' => 'Grit'];

        yield 'save a flow' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->saveNarrativeItem(NarrativeSection::Flows, null, ['key' => 'night', 'name' => 'Night'], $now), 'save a flow'];
        yield 'delete a flow' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->deleteNarrativeItem(NarrativeSection::Flows, 'night', $now), 'delete a flow'];
        yield 'save a sheet field' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->saveSheetField(null, $field, $now), 'save a sheet field'];
        yield 'delete a sheet field' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->deleteSheetField('luck', $now), 'delete a sheet field'];
        yield 'move a sheet field' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->moveSheetField('luck', 0, $now), 'move a sheet field'];
        yield 'save a check' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->saveCheck(null, $check, $now), 'save a check'];
        yield 'delete a check' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->deleteCheck('grit', $now), 'delete a check'];
        yield 'move a check' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->moveCheck('grit', 0, $now), 'move a check'];
        yield 'save a sheet layout' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->saveSheetLayout(2, [], $now), 'save a sheet layout'];
        yield 'declare a sheet layout' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->declareSheetLayout($now), 'declare a sheet layout'];
        yield 'remove a sheet layout' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->removeSheetLayout($now), 'remove a sheet layout'];
        yield 'a theme' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->editMetadata(GameSystemMetadata::of('Names', null, null, 'noir'), $now), 'have a theme'];
        yield 'credits' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->editMetadata(GameSystemMetadata::of('Names', null, ['notice' => 'By me'], null), $now), 'have credits'];
        yield 'restore a release' => [static fn (GameSystem $pack, \DateTimeImmutable $now) => $pack->restore(['schemaVersion' => 2, 'gameSystem' => ['key' => 'other', 'name' => 'Other']], $now), 'restore a release'];
    }

    /**
     * @param \Closure(GameSystem, \DateTimeImmutable): void $operation
     */
    #[Test]
    #[DataProvider('refusedOperations')]
    public function aPackRefusesWhatBelongsToAGameSystemAndStaysUntouched(\Closure $operation, string $named): void
    {
        $pack = $this->pack();
        $before = $pack->document();

        try {
            $operation($pack, $this->now);
            self::fail('The pack accepted the operation.');
        } catch (LibraryPackRefuses $refusal) {
            self::assertStringContainsString($named, $refusal->getMessage());
            self::assertStringContainsString('"fantasy-names"', $refusal->getMessage());
        }

        self::assertSame($before, $pack->document());
        self::assertEquals($this->created, $pack->updatedAt());
        self::assertSame(1, $pack->version());
        self::assertSame([], $pack->libraryImports());
    }

    #[Test]
    public function aPackKeepsNameAndDescriptionEditable(): void
    {
        $pack = $this->pack();

        $pack->editMetadata(GameSystemMetadata::of('Names, revised', 'More tables', null, null), $this->now);

        self::assertSame(['key' => 'fantasy-names', 'name' => 'Names, revised', 'description' => 'More tables'], $pack->document()['gameSystem']);
        self::assertEquals($this->now, $pack->updatedAt());
    }

    #[Test]
    public function aPackEditsOraclesTrackersTagsFactSlotsAndSceneTypesLikeADraft(): void
    {
        $pack = $this->pack();

        $pack->saveOracleTable(null, ['key' => 'npc-name', 'name' => 'NPC name', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]], $this->now);
        $pack->saveLikelihoodOracle(null, ['key' => 'fate', 'name' => 'Fate', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::Trackers, null, ['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang'], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::FactSlots, null, ['key' => 'rival', 'label' => 'Rival', 'type' => 'npc'], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::SceneTypes, null, ['key' => 'social', 'name' => 'Social', 'purpose' => 'Talk.', 'oracles' => ['npc-name'], 'setup' => [], 'play' => [], 'closing' => []], $this->now);
        $pack->deleteNarrativeItem(NarrativeSection::Tags, 'gang', $this->now);

        /** @var array{oracles: array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>}, trackers: list<array<string, mixed>>, tags: list<array<string, mixed>>, factSlots: list<array<string, mixed>>, sceneTypes: list<array<string, mixed>>} $document */
        $document = $pack->document();
        self::assertSame(['npc-name'], array_column($document['oracles']['tables'], 'key'));
        self::assertSame(['fate'], array_column($document['oracles']['likelihood'], 'key'));
        self::assertSame([[], ['chaos'], ['rival'], ['social']], [$document['tags'], array_column($document['trackers'], 'key'), array_column($document['factSlots'], 'key'), array_column($document['sceneTypes'], 'key')]);
        self::assertSame(GameSystemKind::LibraryPack, $pack->kind());
        ReleaseContent::checkItemRules($document);
    }
}
