<?php

namespace App\Console\Commands;

use App\Services\NginxLogParser;
use Illuminate\Console\Command;

/**
 * Console command to parse a Nginx log file and output the results in a specified format.
 */
class ParseLogFile extends Command
{
    /**
     * The signature of the console command.
     *
     * @var string
     */
    protected $signature   = 'app:parse-log-file
                           {file : Path to the log file}
                           {--batch-size=1000 : Number of lines per row counter for batch}
                           {--output=array : Output format (array|json)}
                           {--pattern= : Force pattern (combined|common|custom1|custom2|pseudonymized)}
                           {--limit= : Maximum number of lines to parse}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Parse an Nginx log file and output the results in a specified format.';

    /**
     * Execute the console command.
     *
     * @param NginxLogParser $parser
     * @return int
     */
    public function handle(NginxLogParser $parser): int
    {
        $filePath     = $this->argument('file');
        $batchSize    = $this->option('batch-size');
        $output       = $this->option('output');
        $forcePattern = $this->option('pattern');
        $limit        = $this->option('limit') ? (int)$this->option('limit') : null;

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return 1;
        }

        $this->info("Parsing {$filePath}...");
        $this->info('File size: ' . $this->formatBytes(filesize($filePath)));

        $totalLines  = 0;
        $progressBar = $this->output->createProgressBar();

        switch ($output) {
            case 'json':
                $this->parseToJson($parser, $filePath, $batchSize, $forcePattern, $limit);
                break;
            default:
                $this->parseToArray($parser, $filePath, $batchSize, $progressBar, $totalLines, $forcePattern, $limit);
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Processed: {$totalLines} lines");

        return 0;
    }

    /**
     * Parses the log file and saves the entries as an array in a PHP file.
     *
     * @param NginxLogParser $parser
     * @param string         $filePath
     * @param int            $batchSize
     * @param mixed          $progressBar
     * @param int            $totalLines
     * @param string|null    $forcePattern
     * @param int|null       $limit
     *
     * @return void
     */
    private function parseToArray(NginxLogParser $parser, string $filePath, int $batchSize, mixed $progressBar, int &$totalLines, ?string $forcePattern, ?int $limit): void
    {
        $allLogs = [];

        foreach ($parser->parseBatches($filePath, $batchSize, $forcePattern) as $batch) {
            $allLogs    = array_merge($allLogs, $batch);
            $totalLines += count($batch);

            $progressBar->advance(count($batch));

            if ($limit && $totalLines >= $limit) {
                $this->warn('The limit of ' . $limit . ' lines has been reached.');
                break;
            }
        }

        $outputPath = storage_path('logs/parsed_nginx_logs.php');
        file_put_contents($outputPath, '<?php return ' . var_export($allLogs, true) . ';');

        $this->info('Array saved to: ' . $outputPath);
    }

    /**
     * Parses the log file and saves the entries as a JSON file.
     *
     * @param NginxLogParser $parser
     * @param string $filePath
     * @param int $batchSize
     * @param string|null $forcePattern
     * @param int|null $limit
     * @return void
     */
    private function parseToJson(NginxLogParser $parser, string $filePath, int $batchSize, ?string $forcePattern, ?int $limit): void
    {
        $outputPath = storage_path('app/private/parsed_nginx_logs.json');
        $handle     = fopen($outputPath, 'w');
        fwrite($handle, "[\n");

        $first = true;
        $count = 0;

        foreach ($parser->parseStream($filePath, $forcePattern) as $logEntry) {
            if (!$first) fwrite($handle, ",\n");

            // DateTime to string for JSON
            if ($logEntry['time_local']) {
                $logEntry['time_local'] = $logEntry['time_local']->toISOString();
            }

            fwrite($handle, json_encode($logEntry, JSON_UNESCAPED_UNICODE));
            $first = false;
            $count++;

            if ($limit && $count >= $limit) {
                $this->info("Limit of {$limit} lines reached.");
                break;
            }
        }

        fwrite($handle, "\n]");
        fclose($handle);

        $this->info("JSON saved to: {$outputPath}");
        $this->info("Processed: {$count} lines");
    }

    /**
     * Formats a byte size as a readable string.
     *
     * @param int $size
     * @return string
     */
    private function formatBytes($size): string
    {
        $units = [
            'B',
            'KB',
            'MB',
            'GB',
        ];
        $base  = log($size, 1024);

        return round(pow(1024, $base - floor($base)), 2) . ' ' . $units[floor($base)];
    }
}
