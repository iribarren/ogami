<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\CreateUser;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\PasswordMustNotBeEmpty;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserIdAlreadyInUse;
use App\Identity\Application\UserIdGenerator;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserMustHaveARole;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create', description: 'Create a user who can sign in')]
final class CreateUserConsoleCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly UserIdGenerator $ids,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $roles = implode('|', array_map(static fn (Role $role): string => $role->value, Role::cases()));

        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email the user signs in with')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('A role (%s); repeat for several', $roles))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The password; asked for (hidden) when omitted in an interactive shell')
            ->addOption('if-missing', null, InputOption::VALUE_NONE, 'Succeed without changes when a user with this email already exists (idempotent seeding)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var list<string> $roles */
        $roles = $input->getOption('role');
        if ([] === $roles) {
            $io->error('Give at least one --role.');

            return Command::FAILURE;
        }

        $password = $this->password($input, $io);
        if (null === $password) {
            return Command::FAILURE;
        }

        $id = $this->ids->generate()->toString();

        try {
            $this->commandBus->dispatch(new CreateUser($id, $email, $password, $roles));
        } catch (EmailAlreadyInUse $exception) {
            if (true === $input->getOption('if-missing')) {
                $io->note($exception->getMessage().' Left unchanged.');

                return Command::SUCCESS;
            }

            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (InvalidEmail|UnknownRole|PasswordMustNotBeEmpty|UserMustHaveARole|UserIdAlreadyInUse $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created user %s with id %s', $email, $id));

        return Command::SUCCESS;
    }

    private function password(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = $input->getOption('password');
        if (\is_string($password)) {
            return $password;
        }

        if (!$input->isInteractive()) {
            $io->error('Give the password with --password when not running interactively.');

            return null;
        }

        $first = $io->askHidden('Password');
        $second = $io->askHidden('Repeat the password');
        if ($first !== $second) {
            $io->error('The passwords do not match.');

            return null;
        }

        return \is_string($first) ? $first : '';
    }
}
