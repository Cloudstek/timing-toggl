<?php

declare(strict_types=1);

namespace App\Command;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Convert command.
 *
 * Convert timing CSV exports to Toggl CSV files for import.
 */
class ConvertCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('convert')
            ->setDescription('Convert timing CSV exports to Toggl CSV files for import.')
            ->addArgument('input', InputArgument::REQUIRED, 'Input CSV file (Timing export)')
            ->addArgument('output', InputArgument::REQUIRED, 'Ouput CSV file')
            ->addOption('email', 'e', InputOption::VALUE_OPTIONAL, 'Toggl account email')
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Override project name')
        ;

        $this
            ->addUsage('examples/input.csv examples/output.csv')
            ->addUsage('-e foo@example.org -p "Test Project" examples/input.csv examples/output.csv')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if input file exists
        if (!file_exists($input->getArgument('input'))) {
            $io->error(sprintf('Could not find input file "%s".', $input->getArgument('input')));

            return 1;
        }

        // Check if output file exists
        if (file_exists($input->getArgument('output'))) {
            $overwrite = $io->confirm('Output file exist, overwrite?', false);

            if ($overwrite !== true) {
                $io->writeln('Execution aborted.');

                return 0;
            }
        }

        // Get Toggl email address
        $togglEmail = $this->getTogglEmail($input, $output);

        // Project name
        $projectName = $input->hasOption('project') ? $input->getOption('project') : null;

        // Get file reader
        $fileReader = IOFactory::createReaderForFile($input->getArgument('input'));
        $inputFile = $fileReader->load($input->getArgument('input'));

        // Read file into array
        $inputFile = $inputFile->getActiveSheet()->toArray(null, false, false, false);

        // Map headers to create associative array
        $headers = array_map('strtolower', array_shift($inputFile));
        $inputFile = array_map(function ($v) use ($headers) {
            return array_combine($headers, $v);
        }, $inputFile);

        // Create output data
        $outputFile = [];

        // Flag to indicate that rows have been skipped and a warning should be shown
        $skippedRows = false;

        // Convert data
        foreach ($inputFile as $row) {
            if ($row === null || !is_array($row)) {
                $skippedRows = true;
                continue;
            }

            $startDate = new \DateTimeImmutable($row['start date']);
            $duration = new \DateInterval(sprintf('PT%dH%dM%dS', ...explode(':', $row['duration'])));

            $outputFile[] = [
                'Email' => $togglEmail,
                'Project' => $projectName ?? $row['project'],
                'Description' => $row['task title'],
                'Start date' => $startDate->format('Y-m-d'),
                'Start time' => $startDate->format('H:i:s'),
                'Duration' => $duration->format('%H:%I:%S')
            ];
        }

        // Clean up
        unset($inputFile);

        // Write output
        try {
            $this->writeTogglOutput($input->getArgument('output'), $outputFile);
        } catch (\Throwable $ex) {
            $io->error($ex->getMessage());

            return 1;
        }

        if ($skippedRows === true) {
            $io->warning('Some rows might have been skipped due to errors, please check the output file before importing');
        }

        $io->success(sprintf('%d entries have been written.', count($outputFile)));

        // Clean up
        unset($outputFile);

        return 0;
    }

    /**
     * Get Toggl account email address.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return string
     */
    private function getTogglEmail(InputInterface $input, OutputInterface $output): string
    {
        $io = new SymfonyStyle($input, $output);

        // Get toggl email address
        $email = $input->hasOption('email') ? $input->getOption('email') : null;

        // Ask for email if it wasn't given as an option
        if (empty($email) || $input->hasOption('email') === false) {
            $email = $io->ask('Please enter your Toggl account email address');
        }

        return $email;
    }

    /**
     * Write Toggl CSV output.
     *
     * @param string $file
     * @param array  $data
     */
    private function writeTogglOutput(string $file, array $data): void
    {
        $fp = fopen($file, 'w');

        if ($fp === false) {
            throw new \Exception(sprintf('Could not open "%s" for writing.', $file));
        }

        // Write headers row
        fputcsv($fp, array_keys($data[0]));

        // Write rows
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);
    }
}
