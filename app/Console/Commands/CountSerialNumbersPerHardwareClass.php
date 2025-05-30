<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * Console command to count serial numbers per hardware class from a JSON file.
 */
class CountSerialNumbersPerHardwareClass extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:count-serial-numbers-per-hardware-class';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Count serial numbers per hardware class from a JSON file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Increase memory limit to handle large JSON files
        ini_set('memory_limit', -1);

        // Define the table header and items
        $tableHeader = [
            'CPU Class',
            'Device Count',
        ];
        $tableItems  = [];

        // Get the content of the JSON file
        $fileContent = Storage::disk('local')
            ->get('parsed_nginx_logs.json');

        // Decode the JSON content into an object
        $entriesObject = json_decode($fileContent);

        // Set decoded object to a Laravel Collection
        $entriesObjectAsCollection = collect($entriesObject);

        // Group the entries by 'serial' and 'specs.mac', count them, and filter for those with more than one device
        $grouped = $entriesObjectAsCollection->groupBy([
            fn (stdClass $item) => isset($item->specs?->cpu) ? trim(substr($item->specs?->cpu, 0, strlen($item->specs?->cpu) - 1)) : 'unknown',
            'serial',
        ])
            // Count the number of devices per CPU class
            ->map(fn($group) => $group->count())
            // Filter to keep only those CPU classes with more than one serial number
            ->filter(function ($count) {
                return $count > 1;
            })
            // Sort the results in descending order
            ->sortDesc();

        // Prepare the table items from the grouped data
        foreach ($grouped as $cpuClass => $count) {
            $tableItems[] = [
                $cpuClass,
                $count,
            ];
        }

        $this->info("Serial Numbers per Hardware Class:");
        $this->table($tableHeader, $tableItems);

        $this->newLine();
        $this->info('The CPU Class with the most serial numbers is: ' . $grouped->keys()
                ->first() . ' with ' . $grouped->first() . ' serial numbers.');
    }
}
