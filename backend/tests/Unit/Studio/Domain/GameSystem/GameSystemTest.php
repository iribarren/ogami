<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystem::class)]
#[CoversClass(GameSystemId::class)]
#[CoversClass(GameSystemMetadata::class)]
#[CoversClass(InvalidGameSystemMetadata::class)]
final class GameSystemTest extends TestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62';
    private const string OWNER = 'user-1';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-15T10:00:00');
    }

    #[Test]
    public function aBlankDraftIsAValidMinimalSchemaVersion2Release(): void
    {
        $gameSystem = GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'my-system', 'My system', 'A test', self::OWNER, $this->now);

        self::assertSame(self::FIRST_ID, $gameSystem->id()->toString());
        self::assertSame('my-system', $gameSystem->key());
        self::assertSame(2, $gameSystem->schemaVersion());
        self::assertTrue($gameSystem->isOwnedBy(self::OWNER));
        self::assertFalse($gameSystem->isOwnedBy('user-2'));
        self::assertEquals($this->now, $gameSystem->createdAt());
        self::assertEquals($this->now, $gameSystem->updatedAt());
        self::assertSame('My system', $gameSystem->name());
        self::assertSame(['key' => 'my-system', 'name' => 'My system', 'description' => 'A test'], $gameSystem->document()['gameSystem'] ?? null);
        self::assertSame('my-system', ReleaseContent::fromArray($gameSystem->document())->gameSystemKey());
    }

    #[Test]
    public function aBlankDraftWithoutDescriptionOmitsIt(): void
    {
        $gameSystem = GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'my-system', 'My system', null, self::OWNER, $this->now);

        self::assertSame(['key' => 'my-system', 'name' => 'My system'], $gameSystem->document()['gameSystem']);
    }

    /**
     * @return iterable<string, array{string, string, ?string, string, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'uppercase key' => ['My-System', 'Name', null, self::OWNER, 'key'];
        yield 'empty key' => ['', 'Name', null, self::OWNER, 'key'];
        yield 'blank name' => ['ok-key', '   ', null, self::OWNER, 'name'];
        yield 'long name' => ['ok-key', str_repeat('n', 101), null, self::OWNER, 'name'];
        yield 'long description' => ['ok-key', 'Name', str_repeat('d', 2001), self::OWNER, 'description'];
        yield 'blank owner' => ['ok-key', 'Name', null, ' ', 'ownerId'];
        yield 'owner longer than the column' => ['ok-key', 'Name', null, str_repeat('u', 256), 'ownerId'];
        yield 'owner with a NUL byte' => ['ok-key', 'Name', null, "user\0-1", 'ownerId'];
    }

    #[Test]
    #[DataProvider('invalidCreations')]
    public function aBlankDraftIsRefusedWhenAFieldIsInvalid(string $key, string $name, ?string $description, string $owner, string $field): void
    {
        try {
            GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), $key, $name, $description, $owner, $this->now);
            self::fail('The draft was created.');
        } catch (InvalidGameSystemMetadata $refusal) {
            self::assertSame($field, $refusal->field);
            self::assertStringStartsWith($field.': ', $refusal->getMessage());
        }
    }

    #[Test]
    public function aCopyKeepsTheSourceDocumentAndReplacesOnlyKeyAndName(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();

        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', null, self::OWNER, $source, $this->now);

        /** @var array{gameSystem: array<string, mixed>} $expected */
        $expected = json_decode(json_encode($source, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $expected['gameSystem']['key'] = 'my-copy';
        $expected['gameSystem']['name'] = 'My copy';
        self::assertSame($expected, $gameSystem->document());
        self::assertSame(2, $gameSystem->schemaVersion());
        self::assertSame('my-copy', ReleaseContent::fromArray($gameSystem->document())->gameSystemKey());
    }

    #[Test]
    public function aCopyTakesAGivenDescriptionOverTheSourceOneAndKeepsItOtherwise(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'gameSystem.description', 'Source text'))->toArray();

        $kept = GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', null, self::OWNER, $source, $this->now);
        $replaced = GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', 'My text', self::OWNER, $source, $this->now);

        /** @var array{gameSystem: array{description: string}} $keptDocument */
        $keptDocument = $kept->document();
        /** @var array{gameSystem: array{description: string}} $replacedDocument */
        $replacedDocument = $replaced->document();
        self::assertSame('Source text', $keptDocument['gameSystem']['description']);
        self::assertSame('My text', $replacedDocument['gameSystem']['description']);
    }

    #[Test]
    public function aCopyIsRefusedForAnInvalidDescription(): void
    {
        $source = ReleaseArrays::fixture('valid/v2-minimal');

        try {
            GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', str_repeat('x', 100000), self::OWNER, $source, $this->now);
            self::fail('The draft was created.');
        } catch (InvalidGameSystemMetadata $refusal) {
            self::assertSame('description', $refusal->field);
        }
    }

    #[Test]
    public function aCopyOfASchemaVersion1ReleaseStaysVersion1(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/contract-doc-example'))->toArray();

        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', null, self::OWNER, $source, $this->now);

        self::assertSame(1, $gameSystem->schemaVersion());
        ReleaseContent::fromArray($gameSystem->document());
    }

    #[Test]
    public function aCopyIsRefusedForAnInvalidKeyOrNameOrSource(): void
    {
        $source = ReleaseArrays::fixture('valid/v2-minimal');
        $refusals = [];
        foreach ([['Bad Key', 'Name', $source], ['ok-key', '', $source], ['ok-key', 'Name', ['schemaVersion' => 3]], ['ok-key', 'Name', ['schemaVersion' => 2, 'gameSystem' => 'x']]] as [$key, $name, $document]) {
            try {
                GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), $key, $name, null, self::OWNER, $document, $this->now);
                self::fail('The draft was created.');
            } catch (InvalidGameSystemMetadata $refusal) {
                $refusals[] = $refusal->field;
            }
        }

        self::assertSame(['key', 'name', 'schemaVersion', 'gameSystem'], $refusals);
    }

    #[Test]
    public function editingMetadataReplacesItAsAWhole(): void
    {
        $gameSystem = $this->blank();
        $later = new \DateTimeImmutable('2026-10-16T10:00:00');

        $gameSystem->editMetadata(GameSystemMetadata::of('Renamed', 'New text', ['notice' => 'By me', 'url' => 'https://example.org/x'], 'noir'), $later);

        self::assertSame(['key' => 'my-system', 'name' => 'Renamed', 'description' => 'New text', 'credits' => ['notice' => 'By me', 'url' => 'https://example.org/x']], $gameSystem->document()['gameSystem'] ?? null);
        self::assertSame('noir', $gameSystem->document()['theme']);
        self::assertEquals($later, $gameSystem->updatedAt());
        self::assertEquals($this->now, $gameSystem->createdAt());

        $gameSystem->editMetadata(GameSystemMetadata::of('Renamed', null, null, null), $later);

        self::assertSame(['key' => 'my-system', 'name' => 'Renamed'], $gameSystem->document()['gameSystem'] ?? null);
        self::assertArrayNotHasKey('theme', $gameSystem->document());
        ReleaseContent::fromArray($gameSystem->document());
    }

    /**
     * @return iterable<string, array{string, ?string, ?array<mixed>, ?string, string}>
     */
    public static function invalidMetadata(): iterable
    {
        yield 'blank name' => [' ', null, null, null, 'name'];
        yield 'long description' => ['Name', str_repeat('d', 2001), null, null, 'description'];
        yield 'credits without notice' => ['Name', null, ['license' => 'CC'], null, 'credits'];
        yield 'credits with an http url' => ['Name', null, ['notice' => 'N', 'url' => 'http://example.org'], null, 'credits'];
        yield 'credits with an unknown badge' => ['Name', null, ['notice' => 'N', 'badge' => 'camarilla'], null, 'credits'];
        yield 'credits with an unknown field' => ['Name', null, ['notice' => 'N', 'author' => 'me'], null, 'credits'];
        yield 'unknown theme' => ['Name', null, null, 'pastel', 'theme'];
    }

    /**
     * @param ?array<mixed> $credits
     */
    #[Test]
    #[DataProvider('invalidMetadata')]
    public function metadataIsRefusedWhenAFieldBreaksTheContract(string $name, ?string $description, ?array $credits, ?string $theme, string $field): void
    {
        try {
            GameSystemMetadata::of($name, $description, $credits, $theme);
            self::fail('The metadata was accepted.');
        } catch (InvalidGameSystemMetadata $refusal) {
            self::assertSame($field, $refusal->field);
        }
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesCreditsAndThemeAndChangesNothing(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/contract-doc-example'))->toArray();
        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString(self::SECOND_ID), 'my-copy', 'My copy', null, self::OWNER, $source, $this->now);
        $before = $gameSystem->document();

        foreach ([['credits', GameSystemMetadata::of('Other', null, ['notice' => 'N'], null)], ['theme', GameSystemMetadata::of('Other', null, null, 'noir')]] as [$field, $metadata]) {
            try {
                $gameSystem->editMetadata($metadata, new \DateTimeImmutable('2026-10-16T10:00:00'));
                self::fail('The metadata was accepted.');
            } catch (InvalidGameSystemMetadata $refusal) {
                self::assertSame($field, $refusal->field);
            }
        }

        self::assertSame($before, $gameSystem->document());
        self::assertEquals($this->now, $gameSystem->updatedAt());

        $gameSystem->editMetadata(GameSystemMetadata::of('Other', 'Text', null, null), $this->now);
        self::assertSame('Other', $gameSystem->name());
    }

    #[Test]
    public function restoringReplacesTheDocumentButKeepsTheKey(): void
    {
        $gameSystem = $this->blank();
        $release = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();
        $later = new \DateTimeImmutable('2026-10-16T10:00:00');

        $gameSystem->restore($release, $later);

        /** @var array{gameSystem: array<string, mixed>, sceneTypes: mixed} $document */
        $document = $gameSystem->document();
        self::assertSame('my-system', $document['gameSystem']['key']);
        self::assertSame('Every part', $document['gameSystem']['name']);
        self::assertSame(json_decode(json_encode($release['sceneTypes'], \JSON_THROW_ON_ERROR), true), $document['sceneTypes']);
        self::assertEquals($later, $gameSystem->updatedAt());
        self::assertTrue($gameSystem->isOwnedBy(self::OWNER));
    }

    #[Test]
    public function restoringARubbishDocumentChangesNothing(): void
    {
        $gameSystem = $this->blank();
        $before = $gameSystem->document();

        $this->expectException(InvalidGameSystemMetadata::class);

        try {
            $gameSystem->restore(['schemaVersion' => 9], new \DateTimeImmutable('2026-10-16T10:00:00'));
        } finally {
            self::assertSame($before, $gameSystem->document());
            self::assertEquals($this->now, $gameSystem->updatedAt());
        }
    }

    private function blank(): GameSystem
    {
        return GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'my-system', 'My system', 'A test', self::OWNER, $this->now);
    }
}
