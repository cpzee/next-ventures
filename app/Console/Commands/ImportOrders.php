<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use App\Models\Customer;
use App\Jobs\ReserveStockJob;
use App\Jobs\SimulatePaymentJob;
use App\Jobs\PaymentCallbackJob;
use App\Models\Order;

class ImportOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // protected $signature = 'app:import-orders';
    protected $signature = 'orders:import {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    // protected $description = 'Command description';
    protected $description = 'Import orders from CSV';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $path = $this->argument('file');
        $path = storage_path('app/imports/' . $this->argument('file'));

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        // Use LazyCollection to stream large CSV files without loading into memory
        if (!is_readable($path)) {
            $this->error("Unable to open file: {$path}");
            return 1;
        }

        $count = 0;
        $collection = \Illuminate\Support\LazyCollection::make(function () use ($path) {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return;
            }
            while (($row = fgetcsv($handle)) !== false) {
                yield $row;
            }
            fclose($handle);
        });

        $rows = $collection->filter()->values();

        // First row is header
        $header = $rows->first();
        if (!$header) {
            $this->error('CSV appears empty');
            return 1;
        }

        $rows->skip(1)->each(function ($row) use ($header, &$count) {
            $data = @array_combine($header, $row);
            if (!$data) {
                $this->warn('Skipping malformed CSV row');
                return;
            }

            if (empty($data['customer_id']) || !Customer::where('id', $data['customer_id'])->exists()) {
                $this->warn("Skipping order {$data['order_id']} - customer not found");
                return;
            }

            // Dispatch a lightweight job that will create/update the order and start the chained workflow
            \App\Jobs\ProcessOrderJob::dispatch($data)->onQueue('imports');
            $this->info("Queued Order: {$data['order_id']}");
            $count++;
        });

        $this->info("Queued {$count} orders for processing.");
    }
}

