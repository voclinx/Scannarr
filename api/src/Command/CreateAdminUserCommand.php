<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'scanarr:create-admin',
    description: 'Create an admin user in the database',
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Admin username')
            ->addArgument('email', InputArgument::OPTIONAL, 'Admin email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Admin password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create Admin User');

        $username = $input->getArgument('username') ?? $io->ask('Username', 'admin');
        $email = $input->getArgument('email') ?? $io->ask('Email', 'admin@scanarr.local');
        $password = $this->resolvePassword($input, $io);

        if ($password === null) {
            $io->error('Password cannot be empty.');

            return Command::FAILURE;
        }

        $validationError = $this->validateUniqueness($username, $email);
        if ($validationError !== null) {
            $io->error($validationError);

            return Command::FAILURE;
        }

        $this->createAdminUser($username, $email, $password);
        $io->success(sprintf('Admin user "%s" (%s) created successfully.', $username, $email));

        return Command::SUCCESS;
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = $input->getArgument('password');
        if ($password !== null) {
            return $password;
        }

        $password = $io->askHidden('Password (hidden)');

        return empty($password) ? null : $password;
    }

    private function validateUniqueness(string $username, string $email): ?string
    {
        if ($this->userRepository->findOneBy(['username' => $username]) !== null) {
            return sprintf('User "%s" already exists.', $username);
        }

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            return sprintf('Email "%s" is already in use.', $email);
        }

        return null;
    }

    private function createAdminUser(string $username, string $email, string $password): void
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRole('ROLE_ADMIN');
        $user->setIsActive(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();
    }
}
