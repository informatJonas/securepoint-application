<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Generator;
use SplFileObject;

/**
 * Service for parsing Nginx log files with support for multiple log formats and batch/stream
 * processing.
 */
class NginxLogParser
{
    /**
     * Array of supported log patterns.
     *
     * @var array
     */
    private array $patterns;

    /**
     * The currently selected log pattern.
     *
     * @var string
     */
    private string $currentPattern;

    /**
     * Whether debug mode is enabled.
     *
     * @var bool
     */
    private bool $debugMode = false;

    /**
     * NginxLogParser constructor. Initializes log patterns.
     */
    public function __construct()
    {
        $this->patterns = [
            // Das korrekte Pattern für Ihr Log-Format (test3 hat funktioniert!)
            'securepoint' => '/^(\S+) (\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\d+) (.*)$/',

            // Backup-Patterns
            'combined'    => '/^(\S+) \S+ (\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"/i',
            'common'      => '/^(\S+) \S+ (\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\d+)/i',
        ];

        $this->currentPattern = 'securepoint';
    }

    /**
     * Enable or disable debug mode.
     *
     * @param bool $debug
     *
     * @return self
     */
    public function setDebugMode(bool $debug): self
    {
        $this->debugMode = $debug;

        return $this;
    }

    /**
     * Analyze the first lines of the file to detect the log format.
     *
     * @param string $filePath
     *
     * @return array
     */
    public function detectLogFormat(string $filePath): array
    {
        $file        = new SplFileObject($filePath);
        $sampleLines = [];
        $lineCount   = 0;

        // Erste 10 Zeilen lesen
        foreach ($file as $line) {
            if ($lineCount >= 10) break;

            $trimmed = trim($line);

            if ($trimmed) {
                $sampleLines[] = $trimmed;
                $lineCount++;
            }
        }

        $results = [];

        foreach ($this->patterns as $name => $pattern) {
            $matches   = 0;
            $debugInfo = [];

            foreach ($sampleLines as $lineNum => $line) {
                if (preg_match($pattern, $line, $matches_array)) {
                    $matches++;

                    if ($this->debugMode && $matches <= 2) {
                        $debugInfo[] = 'Line ' . ($lineNum + 1) . ' matched with ' . count($matches_array) . ' groups';
                    }
                }
            }

            $results[$name] = [
                'matches'    => $matches,
                'percentage' => $lineCount > 0 ? round(($matches / $lineCount) * 100, 2) : 0,
                'debug_info' => $debugInfo,
            ];
        }

        // Bestes Pattern finden
        $bestPattern = 'securepoint';
        $bestScore   = 0;

        foreach ($results as $name => $result) {
            if ($result['matches'] > $bestScore) {
                $bestScore   = $result['matches'];
                $bestPattern = $name;
            }
        }

        return [
            'sample_lines'        => $sampleLines,
            'pattern_results'     => $results,
            'recommended_pattern' => $bestPattern,
        ];
    }

    /**
     * Stream-based parsing of the log file.
     *
     * @param string      $filePath
     * @param string|null $forcePattern
     *
     * @return Generator
     */
    public function parseStream(string $filePath, ?string $forcePattern = null): Generator
    {
        if (!$forcePattern) {
            $detection            = $this->detectLogFormat($filePath);
            $this->currentPattern = $detection['recommended_pattern'];

            if ($this->debugMode) {
                echo "Auto-detected pattern: {$this->currentPattern}\n";
            }
        } else {
            $this->currentPattern = $forcePattern;
        }

        $file = new SplFileObject($filePath);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $lineNumber  = 0;
        $parsedCount = 0;
        $errorCount  = 0;

        foreach ($file as $line) {
            if ($file->eof()) break;

            $lineNumber++;
            $line = trim($line);

            if (empty($line)) continue;

            $parsedLine = $this->parseLine($line, $lineNumber);

            if ($parsedLine) {
                $parsedCount++;
                yield $parsedLine;
            } else {
                $errorCount++;

                if ($this->debugMode && $errorCount <= 5) {
                    echo "Failed to parse line {$lineNumber}: " . substr($line, 0, 120) . "...\n";
                }
            }

            // Progress für große Dateien
            if ($this->debugMode && $lineNumber % 10000 === 0) {
                echo "Processed {$lineNumber} lines, parsed: {$parsedCount}, errors: {$errorCount}\n";
            }
        }

        if ($this->debugMode) {
            echo "Final stats - Total lines: {$lineNumber}, Parsed: {$parsedCount}, Errors: {$errorCount}\n";
        }
    }

    /**
     * Parse a single log line.
     *
     * @param string $line
     * @param int    $lineNumber
     *
     * @return array|null
     */
    private function parseLine(string $line, int $lineNumber = 0): ?array
    {
        $pattern = $this->patterns[$this->currentPattern] ?? $this->patterns['securepoint'];

        if (preg_match($pattern, $line, $matches)) {
            try {
                return $this->formatMatches($matches, $line);
            } catch (Exception $e) {
                if ($this->debugMode) {
                    echo "Error formatting line {$lineNumber}: " . $e->getMessage() . "\n";
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Format the matches from a parsed log line into an associative array.
     *
     * @param array  $matches
     * @param string $originalLine
     *
     * @return array
     * @throws Exception
     */
    private function formatMatches(array $matches, string $originalLine): array
    {
        switch ($this->currentPattern) {
            case 'securepoint':
                // matches[1] = IP, matches[2] = Host, matches[3] = Timestamp,
                // matches[4] = Request, matches[5] = Status, matches[6] = Size,
                // matches[7] = Custom fields (proxy=... rt=... serial=... etc.)

                $customFields = $this->parseCustomFields($matches[7]);

                return [
                    'remote_addr'     => $matches[1],
                    'host'            => $matches[2],
                    'remote_user'     => null,
                    'time_local'      => $this->parseDateTime($matches[3]),
                    'status'          => (int)$matches[5],
                    'body_bytes_sent' => (int)$matches[6],
                    'http_referer'    => null,
                    // Nicht in diesem Format
                    'http_user_agent' => null,
                    // Nicht in diesem Format
                    'proxy'           => $customFields['proxy'] ?? null,
                    'response_time'   => $customFields['rt'] ?? null,
                    'serial'          => $customFields['serial'] ?? null,
                    'version'         => $customFields['version'] ?? null,
                    'specs'           => $customFields['specs'] ? json_decode(gzdecode(base64_decode($customFields['specs']))) : null,
                    'not_after'       => $customFields['not_after'] ?? null,
                    'remaining_days'  => $customFields['remaining_days'] ?? null,
                ];

            case 'combined':
                return [
                    'remote_addr'     => $matches[1],
                    'host'            => null,
                    'remote_user'     => isset($matches[2]) && $matches[2] !== '-' ? $matches[2] : null,
                    'time_local'      => $this->parseDateTime($matches[3]),
                    'request'         => $this->parseRequest($matches[4]),
                    'status'          => (int)$matches[5],
                    'body_bytes_sent' => (int)$matches[6],
                    'http_referer'    => isset($matches[7]) && $matches[7] !== '-' ? $matches[7] : null,
                    'http_user_agent' => $matches[8] ?? '',
                    'custom_fields'   => [],
                    'raw_line'        => $originalLine,
                ];

            default:
                throw new Exception("Unknown pattern: {$this->currentPattern}");
        }
    }

    /**
     * Parse custom fields (e.g. proxy, rt, serial, etc.) from a string.
     *
     * @param string $customString
     *
     * @return array
     */
    private function parseCustomFields(string $customString): array
    {
        $fields = [];

        if (preg_match_all('/(\w+)=([^\s]+|"[^"]*")/', $customString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key   = $match[1];
                $value = $match[2];

                if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                }

                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Split a request string into its components.
     *
     * @param string $request
     *
     * @return array
     */
    private function parseRequest(string $request): array
    {
        $parts = explode(' ', $request, 3);

        return [
            'method'   => $parts[0] ?? '',
            'uri'      => $parts[1] ?? '',
            'protocol' => $parts[2] ?? '',
            'full'     => $request,
        ];
    }

    /**
     * Parse a date/time string into a Carbon object.
     *
     * @param string $timeLocal
     *
     * @return Carbon|null
     */
    private function parseDateTime(string $timeLocal): ?Carbon
    {
        try {
            // ISO Format: "2023-02-26T00:00:09+01:00"
            return Carbon::parse($timeLocal);
        } catch (Exception $e) {
            if ($this->debugMode) {
                echo "DateTime parse error for '{$timeLocal}': " . $e->getMessage() . "\n";
            }

            return null;
        }
    }

    /**
     * Parse the log file in batches and yield arrays of log entries.
     *
     * @param string      $filePath
     * @param int         $batchSize
     * @param string|null $forcePattern
     *
     * @return Generator
     */
    public function parseBatches(string $filePath, int $batchSize = 1000, ?string $forcePattern = null): Generator
    {
        $batch = [];
        $count = 0;

        foreach ($this->parseStream($filePath, $forcePattern) as $logEntry) {
            $batch[] = $logEntry;
            $count++;

            if ($count >= $batchSize) {
                yield $batch;
                $batch = [];
                $count = 0;
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }
}