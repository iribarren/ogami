<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Infrastructure\Console\ExportGameSystemReleaseConsoleCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExportGameSystemReleaseConsoleCommand::class)]
final class ExportGameSystemReleaseConsoleCommandTest extends KernelTestCase
{
    private const string RELEASES = __DIR__.'/../../Fixtures/Studio/releases/valid';
    private const string V1 = self::RELEASES.'/contract-doc-example.json';
    private const string V2 = self::RELEASES.'/v2-contract-doc-example.json';

    private CommandTester $export;
    private CommandTester $publish;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->export = new CommandTester($application->find('app:gamesystem:export'));
        $this->publish = new CommandTester($application->find('app:gamesystem:publish'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function itWritesTheLatestReleaseToStdoutAsPrettyJsonWithATrailingNewline(): void
    {
        $this->publishFile(self::V2);

        $exitCode = $this->export->execute(['key' => 'example-heist']);

        self::assertSame(Command::SUCCESS, $exitCode, $this->export->getDisplay());
        $json = $this->export->getDisplay();
        self::assertStringStartsWith("{\n    \"schemaVersion\": 2,\n", $json);
        self::assertStringEndsWith("}\n", $json);
        self::assertStringContainsString("    \"gameSystem\": {\n        \"key\": \"example-heist\",\n", $json);
    }

    #[Test]
    public function itWritesStyleTagShapedTextAndBackslashesToStdoutByteForByte(): void
    {
        $this->publishFile($this->variantOfV1('Mind the <info>tag</info>, <fg=red>red</>, and a \\<escaped bracket.'));

        $exitCode = $this->export->execute(['key' => 'example-journal']);

        self::assertSame(Command::SUCCESS, $exitCode, $this->export->getDisplay());
        $content = json_decode($this->export->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($content);
        self::assertSame($this->releases()->latestFor('example-journal')?->contentHash(), ReleaseContent::fromArray($content)->hash());
    }

    #[Test]
    public function itWritesTheChosenReleaseToTheOutputFile(): void
    {
        $this->publishFile(self::V1);
        $this->publishFile($this->variantOfV1('A different second release.'));
        $output = $this->temporaryFile();

        $exitCode = $this->export->execute(['key' => 'example-journal', '--release' => '1', '--output' => $output]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->export->getDisplay());
        $first = $this->releases()->get('example-journal', 1)?->contentHash();
        self::assertNotSame($first, $this->releases()->get('example-journal', 2)?->contentHash());
        self::assertSame($first, $this->canonicalHashOf($output));
        self::assertStringContainsString('Exported example-journal v1', $this->display());
    }

    #[Test]
    public function itExportsTheLatestVersionByDefault(): void
    {
        $this->publishFile(self::V1);
        $this->publishFile(self::V1);
        $output = $this->temporaryFile();

        $this->export->execute(['key' => 'example-journal', '--output' => $output]);

        self::assertStringContainsString('Exported example-journal v2', $this->display());
    }

    #[Test]
    public function theExportedFileModeFollowsTheUmask(): void
    {
        $this->publishFile(self::V1);
        $output = $this->temporaryFile();
        unlink($output);
        $previous = umask(0o022);

        try {
            $this->export->execute(['key' => 'example-journal', '--output' => $output]);
            clearstatcache(true, $output);
        } finally {
            umask($previous);
        }

        self::assertSame(0o644, fileperms($output) & 0o777);
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function theExportedFileHashesLikeTheReleaseAndCountsAsUnchangedWhenPublishedAgain(string $fixture, string $key): void
    {
        $this->publishFile($fixture);
        $output = $this->temporaryFile();
        $this->export->execute(['key' => $key, '--output' => $output]);

        self::assertSame($this->releases()->latestFor($key)?->contentHash(), $this->canonicalHashOf($output));
        $exitCode = $this->publishFile($output, ['--if-changed' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->publish->getDisplay());
        self::assertStringContainsString(\sprintf('Unchanged %s (v1)', $key), $this->publish->getDisplay());
        self::assertSame(1, $this->releases()->latestFor($key)?->version());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'schema version 1' => [self::V1, 'example-journal'];
        yield 'schema version 2' => [self::V2, 'example-heist'];
    }

    #[Test]
    public function theExportedFileOfAStudioOwnedKeyIsUnchangedWhenPublishedOnlyIfChanged(): void
    {
        $this->publishFile(self::V1);
        self::getContainer()->get(GameSystemRepository::class)->add(GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'), 'example-journal', 'Mine', null, 'ann', new \DateTimeImmutable()));
        $output = $this->temporaryFile();
        $this->export->execute(['key' => 'example-journal', '--output' => $output]);

        $exitCode = $this->publishFile($output, ['--if-changed' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->publish->getDisplay());
        self::assertStringContainsString('Unchanged example-journal (v1, managed in Studio)', $this->publish->getDisplay());
        self::assertSame(1, $this->releases()->latestFor('example-journal')?->version());

        $exitCode = $this->publishFile($output);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('belongs to a GameSystem authored in Studio', (string) preg_replace('/\s+/', ' ', $this->publish->getDisplay()));
    }

    #[Test]
    public function itFailsForAnUnknownKey(): void
    {
        $exitCode = $this->export->execute(['key' => 'no-such-system']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No published release of GameSystem "no-such-system".', $this->display());
        self::assertSame('', $this->stdoutJson());
    }

    #[Test]
    public function itFailsForAnUnknownVersionWithoutWritingTheFile(): void
    {
        $this->publishFile(self::V1);
        $output = $this->temporaryFile();
        unlink($output);

        $exitCode = $this->export->execute(['key' => 'example-journal', '--release' => '7', '--output' => $output]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No published release v7 of GameSystem "example-journal".', $this->display());
        self::assertFileDoesNotExist($output);
    }

    #[Test]
    #[DataProvider('badVersions')]
    public function itFailsForAReleaseThatIsNotAPositiveInteger(string $release): void
    {
        $this->publishFile(self::V1);

        $exitCode = $this->export->execute(['key' => 'example-journal', '--release' => $release]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--release must be a positive whole number', $this->display());
        self::assertSame('', $this->stdoutJson());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badVersions(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'text' => ['latest'];
        yield 'empty' => [''];
    }

    #[Test]
    public function itFailsWhenTheOutputCannotBeWritten(): void
    {
        $this->publishFile(self::V1);

        $exitCode = $this->export->execute(['key' => 'example-journal', '--output' => sys_get_temp_dir().'/no-such-directory/release.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Cannot write the file', $this->display());
    }

    #[Test]
    public function itKeepsAnExistingOutputFileWhenTheExportFails(): void
    {
        $output = $this->temporaryFile();
        file_put_contents($output, 'keep me');

        $exitCode = $this->export->execute(['key' => 'no-such-system', '--output' => $output]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('keep me', file_get_contents($output));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function publishFile(string $file, array $options = []): int
    {
        return $this->publish->execute(['file' => $file] + $options);
    }

    /**
     * A copy of the schema version 1 fixture with another description, so its hash differs.
     */
    private function variantOfV1(string $description): string
    {
        $content = json_decode((string) file_get_contents(self::V1), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($content);
        $content['gameSystem'] = ['description' => $description] + (array) $content['gameSystem'];
        $file = $this->temporaryFile();
        file_put_contents($file, json_encode($content, \JSON_THROW_ON_ERROR));

        return $file;
    }

    private function canonicalHashOf(string $file): string
    {
        $content = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($content);

        return ReleaseContent::fromArray($content)->hash();
    }

    /**
     * What the export printed as the document: nothing when it failed.
     */
    private function stdoutJson(): string
    {
        return str_starts_with($this->export->getDisplay(), '{') ? $this->export->getDisplay() : '';
    }

    /**
     * The output with SymfonyStyle's line wrapping undone.
     */
    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->export->getDisplay());
    }

    private function releases(): GameSystemReleaseRepository
    {
        return self::getContainer()->get(GameSystemReleaseRepository::class);
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'export');
        self::assertIsString($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
