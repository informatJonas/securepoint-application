<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Console command to get the serial numbers with the most queries from a JSON file.
 */
class GetMostQueries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-most-queries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the serial number with the most queries from a JSON file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Increase memory limit to handle large JSON files
        ini_set('memory_limit', -1);

        // Define the table header and items
        $tableHeader = [
            'Serial Number',
            'Count',
        ];
        $tableItems  = [];

        // Get the content of the JSON file
        $fileContent = Storage::disk('local')
            ->get('parsed_nginx_logs.json');

        // Decode the JSON content into an object
        $entriesObject = json_decode($fileContent);

        // Set decoded object to a Laravel Collection
        $entriesObjectAsCollection = collect($entriesObject);

        // Group by 'serial'
        $grouped = $entriesObjectAsCollection->groupBy('serial', preserveKeys: true)
            // Count the occurrences of each serial number
            ->map(fn ($group) => $group->count())
            // Take only the top 10 serial numbers with the most queries
            ->take(10)
            // Sort the collection in descending order by count
            ->sortDesc();

        $this->info("Top 10 Serial Numbers with Most Queries:");

        // Prepare table items
        foreach ($grouped as $serial => $count) {
            $tableItems[] = [
                $serial,
                $count,
            ];
        }

        $this->table($tableHeader, $tableItems);

        $this->newLine();
        $this->info('The Serial Number with the most queries is: ' . $grouped->keys()
                ->first() . ' with ' . $grouped->first() . ' queries.');
    }
}
