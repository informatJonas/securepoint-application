<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;

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
            function (stdClass $item) {
                if (isset($item->specs?->cpu) === true) {
                    $trimmedCpu = trim(substr($item->specs?->cpu, 0, strlen($item->specs?->cpu) - 1));

                    return $trimmedCpu . '_' . $item->specs?->architecture . '_' . $item->specs?->machine;
                }

                Str::snake()

                return 'unknown';
            },
            'serial',
        ])
            ->map(function ($group) {
                return $group->count();
            })
            ->filter(function ($count) {
                return $count > 1;
            })
            ->sortDesc();

        dd($grouped);
    }
}
