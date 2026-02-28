<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\QBittorrentSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scanarr:sync-qbittorrent',
    description: 'Sync torrent stats from qBittorrent',
)]
class SyncQBittorrentCommand extends Command
{
    public function __construct(
        private readonly QBittorrentSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Syncing qBittorrent torrent stats...');

        $result = $this->syncService->sync();

        $io->success(sprintf(
            'Synced %d torrents, %d new trackers, %d unmatched',
            $result['torrents_synced'],
            $result['new_trackers'],
            $result['unmatched'],
        ));

        if ($result['errors'] > 0) {
            $io->warning(sprintf('%d errors during sync', $result['errors']));
        }

        if ($result['stale_removed'] > 0) {
            $io->note(sprintf('%d stale torrents marked as removed', $result['stale_removed']));
        }

        return Command::SUCCESS;
    }
}
