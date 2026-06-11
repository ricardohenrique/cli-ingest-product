<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\IngestProductFeed\IngestProductFeedHandler;
use App\Application\IngestProductFeed\IngestProductFeedInput;
use App\Domain\Port\Driven\FeedReaderPort;
use App\Domain\Port\Driven\RowWriterPort;
use App\Domain\ProductFeed\Exception\ProductFeedException;
use App\Domain\ProductFeed\ProductFlattener;
use App\Domain\ProductFeed\ProductRowValidator;
use App\Infrastructure\Output\CsvFileRowWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'feed:ingest', description: 'Ingest a JSONL product feed and write flattened rows to the database')]
final class IngestProductFeedSymfonyCommand extends Command
{
    public function __construct(
        private readonly FeedReaderPort $reader,
        private readonly ProductFlattener $flattener,
        private readonly ProductRowValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly RowWriterPort $doctrineWriter,
        private readonly string $defaultCsvOutputPath,
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

        $this->addOption(
            'writer',
            null,
            InputOption::VALUE_REQUIRED,
            'Output writer to use: postgres or csv',
            'postgres',
        );

        $this->addOption(
            'output-path',
            null,
            InputOption::VALUE_REQUIRED,
            'Output file path for the CSV writer (overrides default; only used with --writer=csv)',
        );

        $this->addOption(
            'field-map',
            null,
            InputOption::VALUE_REQUIRED,
            'Rename CSV header columns: original:renamed,... (only with --writer=csv)',
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

        $writerName = $input->getOption('writer');
        $outputPath = $input->getOption('output-path');

        $fieldMap = [];
        $rawMap = $input->getOption('field-map');

        if ($rawMap !== null) {
            if ($writerName !== 'csv') {
                $io->error('--field-map is only supported with --writer=csv');

                return Command::FAILURE;
            }

            foreach (explode(',', $rawMap) as $pair) {
                $parts = explode(':', $pair, 2);

                if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                    $io->error(sprintf('Invalid --field-map pair "%s". Expected format: original:renamed', $pair));

                    return Command::FAILURE;
                }

                $fieldMap[trim($parts[0])] = trim($parts[1]);
            }
        }

        $writer = match ($writerName) {
            'postgres' => $this->doctrineWriter,
            'csv' => new CsvFileRowWriter(
                $outputPath ?? $this->defaultCsvOutputPath,
                $this->logger,
                $fieldMap,
            ),
            default => null,
        };

        if ($writer === null) {
            $io->error(sprintf('Unknown writer "%s". Supported: postgres, csv', $writerName));

            return Command::FAILURE;
        }

        $handler = new IngestProductFeedHandler(
            $this->reader,
            $writer,
            $this->flattener,
            $this->validator,
            $this->logger,
        );

        try {
            $result = $handler->handle(new IngestProductFeedInput($path));
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
