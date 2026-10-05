<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Infrastructure\Console\PublishGameSystemReleaseConsoleCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PublishGameSystemReleaseConsoleCommand::class)]
final class PublishGameSystemReleaseConsoleCommandTest extends KernelTestCase
{
    private const string RELEASES = __DIR__.'/../../Fixtures/Studio/releases';
    private const string EXAMPLE = self::RELEASES.'/valid/contract-doc-example.json';

    private CommandTester $tester;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:gamesystem:publish'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function itPublishesTheFirstVersion(): void
    {
        $exitCode = $this->tester->execute(['file' => self::EXAMPLE]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Published example-journal v1', $this->tester->getDisplay());
        self::assertSame(1, $this->releases()->latestFor('example-journal')?->version());
    }

    #[Test]
    public function itPublishesTheNextVersionOfTheSameFile(): void
    {
        $this->tester->execute(['file' => self::EXAMPLE]);

        $exitCode = $this->tester->execute(['file' => self::EXAMPLE]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Published example-journal v2', $this->tester->getDisplay());
        self::assertSame(2, $this->releases()->latestFor('example-journal')?->version());
    }

    #[Test]
    public function itLeavesAnUnchangedGameSystemAloneWhenAskedToPublishOnlyIfChanged(): void
    {
        $this->tester->execute(['file' => self::EXAMPLE, '--if-changed' => true]);

        $exitCode = $this->tester->execute(['file' => self::EXAMPLE, '--if-changed' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Unchanged example-journal (v1)', $this->tester->getDisplay());
        self::assertSame(1, $this->releases()->latestFor('example-journal')?->version());
    }

    #[Test]
    public function itRejectsInvalidContentNamingThePath(): void
    {
        $exitCode = $this->tester->execute(['file' => self::RELEASES.'/invalid/structural/bad-step-key.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('is not a valid GameSystem release. flow.steps[0].key: must be 1 to 64 characters', $this->display());
    }

    #[Test]
    public function itRejectsMalformedJson(): void
    {
        $exitCode = $this->tester->execute(['file' => $this->temporaryFile('{"schemaVersion": 1,')]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not valid JSON', $this->tester->getDisplay());
    }

    #[Test]
    public function itRejectsJsonThatIsNotAnObject(): void
    {
        $exitCode = $this->tester->execute(['file' => $this->temporaryFile('"free-journal"')]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('must contain a JSON object', $this->tester->getDisplay());
    }

    #[Test]
    public function itRejectsAMissingFile(): void
    {
        $exitCode = $this->tester->execute(['file' => self::RELEASES.'/no-such-release.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Cannot read', $this->tester->getDisplay());
    }

    /**
     * The output with SymfonyStyle's line wrapping undone.
     */
    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }

    private function releases(): GameSystemReleaseRepository
    {
        return self::getContainer()->get(GameSystemReleaseRepository::class);
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'release');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
