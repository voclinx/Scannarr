<?php

namespace App\Command;

use App\Repository\TorrentStatHistoryRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scanarr:clean-torrent-history',
    description: 'Delete torrent stat history older than 90 days',
)]
class CleanTorrentHistoryCommand extends Command
{
    public function __construct(
        private readonly TorrentStatHistoryRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new DateTimeImmutable('-90 days');

        $deleted = $this->repository->deleteOlderThan($cutoff);

        $io->success(sprintf('Deleted %d old history records', $deleted));

        return Command::SUCCESS;
    }
}
