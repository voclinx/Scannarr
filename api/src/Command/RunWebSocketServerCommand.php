<?php

namespace App\Command;

use App\WebSocket\WatcherWebSocketServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:websocket:run',
    description: 'Start the WebSocket server for watcher communication',
)]
class RunWebSocketServerCommand extends Command
{
    public function __construct(
        private WatcherWebSocketServer $webSocketServer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Host to listen on', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port to listen on', '8081');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');

        $io->info("Starting WebSocket server on {$host}:{$port}");

        $this->webSocketServer->run($host, $port);

        return Command::SUCCESS;
    }
}
