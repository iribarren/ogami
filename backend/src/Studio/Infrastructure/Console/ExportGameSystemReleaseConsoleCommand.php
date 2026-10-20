<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Console;

use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\PublishedReleaseNotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a published release as a preset file (ADR 0036): the content exactly as published, so
 * app:gamesystem:publish --if-changed finds the same hash in it.
 */
#[AsCommand(name: 'app:gamesystem:export', description: 'Write a published GameSystem release as a preset JSON file')]
final class ExportGameSystemReleaseConsoleCommand extends Command
{
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Key of the GameSystem')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Version to export (default: the latest)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'File to write (default: standard output)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $key */
        $key = $input->getArgument('key');
        $version = $this->version($input->getOption('release'));
        if (false === $version) {
            $io->error('--release must be a positive whole number.');

            return Command::FAILURE;
        }

        try {
            $release = $this->queryBus->ask(new GetPublishedRelease($key, $version));
        } catch (PublishedReleaseNotFound $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $json = json_encode($release->content, self::JSON_FLAGS)."\n";
        $file = $input->getOption('output');
        if (null === $file) {
            $output->write($json);

            return Command::SUCCESS;
        }

        \assert(\is_string($file));
        if (!$this->write($file, $json)) {
            $io->error(\sprintf('Cannot write the file %s.', $file));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Exported %s v%d to %s', $release->gameSystemKey, $release->version, $file));

        return Command::SUCCESS;
    }

    /**
     * @return int|false|null the version, null for the latest, false when the option is not a positive whole number
     */
    private function version(mixed $option): int|false|null
    {
        if (null === $option) {
            return null;
        }

        if (!\is_string($option) || 1 !== preg_match('/^[1-9][0-9]{0,8}$/', $option)) {
            return false;
        }

        return (int) $option;
    }

    /**
     * All or nothing: the target is replaced only once the whole content is on disk beside it.
     */
    private function write(string $file, string $json): bool
    {
        $directory = \dirname($file);
        $temporary = is_dir($directory) && is_writable($directory) ? tempnam($directory, '.export-') : false;
        if (false === $temporary) {
            return false;
        }

        if (false === @file_put_contents($temporary, $json) || !@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
