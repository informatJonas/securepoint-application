<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Console command to find serial numbers associated with multiple devices from a JSON file.
 */
class GetSerialNumbersWithMultipleDevices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-serial-numbers-with-multiple-devices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get serial numbers with multiple devices from a JSON file';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        // Increase memory limit to handle large JSON files
        ini_set('memory_limit', -1);

        // Define the table header and items
        $tableHeader = [
            'Serial Number',
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

        // Group the entries by 'serial' and 'specs.mac'
        $grouped = $entriesObjectAsCollection->groupBy([
            'serial',
            'specs.mac',
        ], preserveKeys: true)
            // Count the occurrences of each serial number
            ->map(fn ($group) => $group->count())
            // Filter to keep only serial numbers with more than one device
            ->filter(fn ($count) => $count > 1)
            ->take(10)
            ->sortDesc();

        // Prepare the table items from the grouped data
        foreach ($grouped as $serial => $count) {
            $tableItems[] = [
                $serial,
                $count,
            ];
        }

        // Output the table
        $this->info('Serial numbers with multiple devices:');
        $this->table($tableHeader, $tableItems);

        $this->info('The serial number with the most devices is:' . $grouped->keys()
                ->first() . ' with ' . $grouped->first() . ' devices.');
    }
}
