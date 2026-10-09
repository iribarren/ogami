<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\CheckGameSystemRelease;
use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\GetPublishedReleaseById;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\ReleaseIdGenerator;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\InvalidReleaseContent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:gamesystem:publish', description: 'Publish a GameSystem release file as the next version of its GameSystem')]
final class PublishGameSystemReleaseConsoleCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly ReleaseIdGenerator $ids,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the release JSON file (docs/contracts/gamesystem-release.md)')
            ->addOption('if-changed', null, InputOption::VALUE_NONE, 'Publish nothing when the latest release of the GameSystem has the same content (idempotent seeding)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $file */
        $file = $input->getArgument('file');
        $content = $this->read($file, $io);
        if (null === $content) {
            return Command::FAILURE;
        }

        $id = $this->ids->generate()->toString();

        try {
            // Valid content publishes; its authoring warnings are told first (decision 7, ADR 0018).
            foreach ($this->queryBus->ask(new CheckGameSystemRelease($content))->warnings as $warning) {
                $output->writeln(\sprintf('Warning: %s', $warning));
            }

            $this->commandBus->dispatch(new PublishGameSystemRelease($id, $content, true === $input->getOption('if-changed')));
        } catch (InvalidReleaseContent $exception) {
            $io->error(\sprintf('%s is not a valid GameSystem release. %s', $file, $exception->getMessage()));

            return Command::FAILURE;
        } catch (GameSystemReleaseAlreadyExists $exception) {
            // Another publish of this GameSystem took the version first; the transaction was rolled back.
            $io->error(\sprintf('Another release of %s v%d was published at the same time; run the command again.', $exception->gameSystemKey, $exception->version));

            return Command::FAILURE;
        }

        // Decided from this run's own release id, so a publish that follows ours cannot change the outcome.
        $published = $this->queryBus->ask(new GetPublishedReleaseById($id));
        if ($published instanceof PublishedReleaseView) {
            $io->success(\sprintf('Published %s v%d', $published->gameSystemKey, $published->version));

            return Command::SUCCESS;
        }

        // Nothing was published, so the content was valid and unchanged: gameSystem.key is a string.
        $gameSystem = $content['gameSystem'];
        \assert(\is_array($gameSystem) && \is_string($gameSystem['key']));

        $latest = $this->queryBus->ask(new GetPublishedRelease($gameSystem['key']));
        $io->note(\sprintf('Unchanged %s (v%d)', $latest->gameSystemKey, $latest->version));

        return Command::SUCCESS;
    }

    /**
     * @return array<mixed>|null the decoded JSON object, or null after reporting why it cannot be read
     */
    private function read(string $file, SymfonyStyle $io): ?array
    {
        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if (false === $json) {
            $io->error(\sprintf('Cannot read the file %s.', $file));

            return null;
        }

        try {
            $content = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $io->error(\sprintf('%s is not valid JSON: %s.', $file, $exception->getMessage()));

            return null;
        }

        if (!\is_array($content)) {
            $io->error(\sprintf('%s must contain a JSON object.', $file));

            return null;
        }

        return $content;
    }
}
