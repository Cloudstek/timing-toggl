<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\UnsupportedFileTypeException;
use Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
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
            ->addArgument('input', InputArgument::REQUIRED, 'Input JSON/CSV file (Timing export)')
            ->addArgument('output', InputArgument::REQUIRED, 'Ouput CSV file or - for stdout')
            ->addOption('email', 'e', InputOption::VALUE_OPTIONAL, 'Toggl account email')
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Override project name')
        ;

        $this
            ->addUsage('examples/input.csv examples/output.csv')
            ->addUsage('examples/input.json examples/output.csv')
            ->addUsage('-e foo@example.org -p "Test Project" examples/input.csv examples/output.csv')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $inputFile */
        $inputFile = $input->getArgument('input');
        /** @var string|null $outputFile */
        $outputFile = $input->getArgument('output');

        // Check if input file exists
        if (empty($inputFile) || file_exists($inputFile) === false) {
            $io->error(sprintf('Could not find input file "%s".', $inputFile));

            return 1;
        }

        // Check output file
        if (empty($outputFile)) {
            $io->error('Invalid output specified.');

            return 1;
        }

        if ($outputFile !== '-' && file_exists($outputFile)) {
            $overwrite = $io->confirm('Output file exist, overwrite?', false);

            if ($overwrite !== true) {
                $io->writeln('Execution aborted.');

                return 0;
            }
        }

        // Get Toggl email address
        $togglEmail = $this->getTogglEmail($input, $output);

        // Project name
        /** @var string|null $projectName */
        $projectName = $input->hasOption('project') ? $input->getOption('project') : null;

        // Flag to indicate that rows have been skipped and a warning should be shown
        $skippedRows = false;

        // Output data
        try {
            $result = $this->readInput($inputFile, $togglEmail, $projectName);

            $outputData = $result['data'];
            $skippedRows = $result['skipped'];
        } catch (\Exception $ex) {
            $io->error($ex->getMessage());
        }

        // Clean up
        unset($inputFile);

        if (empty($outputData)) {
            $io->warning('Input file did not contain any data!');

            return 0;
        }

        // Write output
        try {
            $this->writeTogglOutput($outputFile, $outputData);
        } catch (\Throwable $ex) {
            $io->error($ex->getMessage());

            return 1;
        }

        if ($outputFile !== '-') {
            if ($skippedRows === true) {
                $io->warning('Some rows might have been skipped due to errors, please check the output file before importing');
            }

            $io->success(sprintf('%d entries have been written.', count($outputData)));
        }

        // Clean up
        unset($outputData);

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
        $outputFile = $file;

        if ($file === '-') {
            $outputFile = tempnam(sys_get_temp_dir(), 'timingtoggl');

            if ($outputFile === false) {
                throw new \Exception('Could not create temporary file for writing.');
            }
        }

        // Add headers row
        array_unshift($data, array_keys($data[0]));

        // Turn data into spreadsheet
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->fromArray($data);

        // Write as CSV file
        $csv = new CsvWriter($spreadsheet);
        $csv->save($outputFile);

        if ($file === '-') {
            echo file_get_contents($outputFile);

            @unlink($outputFile);
        }
    }

    /**
     * Read input file.
     *
     * @param string      $file
     * @param string      $email
     * @param string|null $project
     *
     * @return array
     */
    private function readInput(string $file, string $email, ?string $project = null): array
    {
        // Input file extension
        $extension = strtolower(pathinfo($file, \PATHINFO_EXTENSION));

        switch ($extension) {
            case 'json':
                return $this->readJson($file, $email, $project);
            case 'csv':
                return $this->readCsv($file, $email, $project);
            default:
                throw new UnsupportedFileTypeException();
        }
    }

    /**
     * Read Timing JSON export.
     *
     * @param string      $file    Input file
     * @param string      $email   Email address
     * @param string|null $project Project name (override)
     *
     * @return array
     */
    private function readJson(string $file, string $email, ?string $project = null): array
    {
        // Read file
        $fileContents = file_get_contents($file);

        if ($fileContents === false) {
            throw new \Exception(sprintf('Could not read file "%s".', $file));
        }

        // Parse data
        $items = json_decode($fileContents, true);

        // Create output data
        $outputData = [];

        // Convert data
        foreach ($items as $item) {
            $startDate = new Carbon\Carbon($item['startDate']);
            $startDate->tz('UTC');
            $duration = new \DateInterval(sprintf('PT%dH%dM%dS', ...explode(':', $item['duration'])));

            $outputData[] = [
                'Email' => $email,
                'Project' => $project ?? $item['project'],
                'Description' => $item['activityTitle'] ?? $item['taskActivityTitle'],
                'Start date' => $startDate->format('Y-m-d'),
                'Start time' => $startDate->format('H:i:s'),
                'Duration' => $duration->format('%H:%I:%S')
            ];
        }

        return [
            'skipped' => false,
            'data' => $outputData
        ];
    }

    /**
     * Read Timing CSV export.
     *
     * @param string      $file    Input file
     * @param string      $email   Email address
     * @param string|null $project Project name (override)
     *
     * @return array
     */
    private function readCsv(string $file, string $email, ?string $project = null): array
    {
        // Get file reader
        $fileReader = IOFactory::createReaderForFile($file);
        $inputFile = $fileReader->load($file);

        // Read file into array
        $inputFile = $inputFile->getActiveSheet()->toArray(null, false, false, false);

        // Map headers to create associative array
        $headers = array_map('strtolower', array_shift($inputFile));
        $inputFile = array_map(function ($v) use ($headers) {
            return array_combine($headers, $v);
        }, $inputFile);

        // Create output data
        $outputData = [];

        // Flag to indicate that rows have been skipped and a warning should be shown
        $skippedRows = false;

        // Convert data
        foreach ($inputFile as $row) {
            if ($row === null || !is_array($row)) {
                $skippedRows = true;
                continue;
            }

            $startDate = new Carbon\Carbon($row['start date']);
            $startDate->tz('UTC');
            $duration = new \DateInterval(sprintf('PT%dH%dM%dS', ...explode(':', $row['duration'])));

            $outputData[] = [
                'Email' => $email,
                'Project' => $project ?? $row['project'],
                'Description' => $row['task title'],
                'Start date' => $startDate->format('Y-m-d'),
                'Start time' => $startDate->format('H:i:s'),
                'Duration' => $duration->format('%H:%I:%S')
            ];
        }

        return [
            'skipped' => $skippedRows,
            'data' => $outputData
        ];
    }
}
