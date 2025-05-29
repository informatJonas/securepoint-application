<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
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
            ->get('parsed_nginx_logs_1748421338.json');

        // Decode the JSON content into an object
        $entriesObject = json_decode($fileContent);

        // Set decoded object to a Laravel Collection
        $entriesObjectAsCollection = collect($entriesObject);

        // Group the entries by 'serial' and 'specs.mac', count them, and filter for those with more than one device
        $grouped = $entriesObjectAsCollection->groupBy([
            'serial',
            'specs.mac',
        ], preserveKeys: true)
            ->map(function ($group) {
                return $group->count();
            })
            ->filter(function ($count) {
                return $count > 1; // Only keep serials with more than one device
            })
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
