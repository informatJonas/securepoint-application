<?php

namespace App\Console\Commands;

use App\Services\NginxLogParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ParseLogFile extends Command
{
    protected $signature   = 'app:parse-log-file 
                           {file : Pfad zur Log-Datei}
                           {--batch-size=1000 : Anzahl Zeilen pro Batch}
                           {--output=array : Output Format (array|database|json)}
                           {--debug : Debug-Modus aktivieren}
                           {--analyze : Nur Log-Format analysieren}
                           {--pattern= : Pattern erzwingen (combined|common|custom1|custom2|pseudonymized)}
                           {--limit= : Maximale Anzahl zu parsender Zeilen}';

    protected $description = 'Parse NGINX Access Logs with improved detection';

    public function handle(NginxLogParser $parser)
    {
        $filePath     = $this->argument('file');
        $batchSize    = $this->option('batch-size');
        $output       = $this->option('output');
        $debug        = $this->option('debug');
        $analyzeOnly  = $this->option('analyze');
        $forcePattern = $this->option('pattern');
        $limit        = $this->option('limit') ? (int)$this->option('limit') : null;

        if (!file_exists($filePath)) {
            $this->error("Datei nicht gefunden: {$filePath}");

            return 1;
        }

        $this->info("Parsing {$filePath}...");
        $this->info('Dateigröße: ' . $this->formatBytes(filesize($filePath)));

        if ($debug) {
            $parser->setDebugMode(true);
        }

        // Log-Format analysieren
        if ($analyzeOnly) {
            $this->analyzeLogFormat($parser, $filePath);

            return 0;
        }

        $totalLines  = 0;
        $progressBar = $this->output->createProgressBar();

        switch ($output) {
            case 'database':
                $this->parseToDatabase($parser, $filePath, $batchSize, $progressBar, $totalLines, $forcePattern, $limit);
                break;
            case 'json':
                $this->parseToJson($parser, $filePath, $batchSize, $forcePattern, $limit);
                break;
            default:
                $this->parseToArray($parser, $filePath, $batchSize, $progressBar, $totalLines, $forcePattern, $limit);
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Verarbeitet: {$totalLines} Zeilen");
    }

    private function analyzeLogFormat(NginxLogParser $parser, string $filePath): void
    {
        $this->info('Analysiere Log-Format...');

        $analysis = $parser->detectLogFormat($filePath);

        $this->info('Beispiel-Zeilen:');

        foreach (array_slice($analysis['sample_lines'], 0, 3) as $i => $line) {
            $this->line('  ' . ($i + 1) . ': ' . substr($line, 0, 120) . (strlen($line) > 120 ? '...' : ''));
        }

        $this->newLine();
        $this->info('Pattern-Analyse:');

        $table = [];
        foreach ($analysis['pattern_results'] as $pattern => $result) {
            $table[] = [
                'Pattern'     => $pattern,
                'Matches'     => $result['matches'],
                'Percentage'  => $result['percentage'] . '%',
                'Recommended' => $pattern === $analysis['recommended_pattern'] ? '✓' : '',
            ];
        }

        $this->table([
            'Pattern',
            'Matches',
            'Percentage',
            'Recommended',
        ], $table);

        $this->info('Empfohlenes Pattern: ' . $analysis['recommended_pattern']);
        $this->info('Verwenden Sie --pattern=' . $analysis['recommended_pattern'] . ' um dieses Pattern zu erzwingen.');
    }

    private function parseToArray(NginxLogParser $parser, string $filePath, int $batchSize, $progressBar, &$totalLines, ?string $forcePattern, ?int $limit): void
    {
        $allLogs = [];

        foreach ($parser->parseBatches($filePath, $batchSize, $forcePattern) as $batch) {
            $allLogs    = array_merge($allLogs, $batch);
            $totalLines += count($batch);
            $progressBar->advance(count($batch));

            if ($limit && $totalLines >= $limit) {
                $this->warn("Limit von {$limit} Zeilen erreicht.");
                break;
            }

            if (memory_get_usage() > 400 * 1024 * 1024) {
                $this->warn('Memory Limit erreicht. Verwenden Sie --output=database für große Dateien.');
                break;
            }
        }

        $outputPath = storage_path('logs/parsed_nginx_logs.php');
        file_put_contents($outputPath, '<?php return ' . var_export($allLogs, true) . ';');
        $this->info("Array gespeichert in: {$outputPath}");
    }

    private function parseToDatabase(NginxLogParser $parser, string $filePath, int $batchSize, $progressBar, &$totalLines, ?string $forcePattern, ?int $limit): void
    {
        foreach ($parser->parseBatches($filePath, $batchSize, $forcePattern) as $batch) {
            // DateTime zu String für DB
            $batch = array_map(function ($item) {
                if ($item['time_local']) {
                    $item['time_local'] = $item['time_local']->toDateTimeString();
                }

                return $item;
            }, $batch);

            DB::table('nginx_logs')
                ->insert($batch);
            $totalLines += count($batch);
            $progressBar->advance(count($batch));

            if ($limit && $totalLines >= $limit) {
                $this->warn("Limit von {$limit} Zeilen erreicht.");
                break;
            }
        }
    }

    private function parseToJson(NginxLogParser $parser, string $filePath, int $batchSize, ?string $forcePattern, ?int $limit): void
    {
        $outputPath = storage_path('app/private/parsed_nginx_logs_' . now()->timestamp . '.json');
        $handle     = fopen($outputPath, 'w');
        fwrite($handle, "[\n");

        $first = true;
        $count = 0;
        foreach ($parser->parseStream($filePath, $forcePattern) as $logEntry) {
            if (!$first) fwrite($handle, ",\n");

            // DateTime zu String für JSON
            if ($logEntry['time_local']) {
                $logEntry['time_local'] = $logEntry['time_local']->toISOString();
            }

            fwrite($handle, json_encode($logEntry, JSON_UNESCAPED_UNICODE));
            $first = false;
            $count++;

            if ($limit && $count >= $limit) {
                $this->info("Limit von {$limit} Zeilen erreicht.");
                break;
            }
        }

        fwrite($handle, "\n]");
        fclose($handle);

        $this->info("JSON gespeichert in: {$outputPath}");
        $this->info("Verarbeitet: {$count} Zeilen");
    }

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
