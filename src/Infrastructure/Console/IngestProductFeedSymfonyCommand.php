<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\IngestProductFeed\IngestProductFeedHandler;
use App\Application\IngestProductFeed\IngestProductFeedInput;
use App\Domain\ProductFeed\Exception\ProductFeedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'feed:ingest', description: 'Ingest a JSONL product feed and write flattened rows to the database')]
final class IngestProductFeedSymfonyCommand extends Command
{
    public function __construct(
        private readonly IngestProductFeedHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Path to the JSONL feed file (or set FEED_INPUT_PATH env variable)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = $input->getArgument('path') ?: ($_ENV['FEED_INPUT_PATH'] ?? null);

        if (!$path) {
            $io->error('No feed path provided. Pass as argument or set the FEED_INPUT_PATH environment variable.');

            return Command::FAILURE;
        }

        try {
            $result = $this->handler->handle(new IngestProductFeedInput($path));
        } catch (ProductFeedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Records processed', 'Rows written', 'Records skipped', 'Errors'],
            [[$result->recordsProcessed, $result->rowsWritten, $result->recordsSkipped, count($result->errors)]],
        );

        if (!empty($result->errors)) {
            $io->section('Skipped records');
            foreach ($result->errors as $error) {
                $io->text('  '.$error);
            }
        }

        return Command::SUCCESS;
    }
}
