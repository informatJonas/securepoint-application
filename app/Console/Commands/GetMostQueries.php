<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
            ->get('parsed_nginx_logs_1748421338.json');

        // Decode the JSON content into an object
        $entriesObject = json_decode($fileContent);

        // Set decoded object to a Laravel Collection
        $entriesObjectAsCollection = collect($entriesObject);

        // Group by 'serial' and count occurrences, then take the top 10
        $grouped = $entriesObjectAsCollection->groupBy('serial', preserveKeys: true)
            ->map(function ($group) {
                return $group->count();
            })
            ->take(10)
            ->sortDesc();

        $this->info("Top 10 Serial Numbers with Most Queries:");

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
