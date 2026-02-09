<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\OrderSyncService;

class SyncSingleOrder extends Command
{
    protected $signature = 'order:sync-one {basket_id}';
    protected $description = 'Sync a single order from the SWSM API by basket_id';

    public function handle()
    {
        $basketId = $this->argument('basket_id');

        $this->info("Fetching order with basket_id: $basketId");

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('app.getwaterfit.api_token'),
            ])->timeout(30)->get(
                config('app.getwaterfit.api_url') . 'suppliers/api/1.0/order/' . $basketId
            );

            if ($response->failed()) {
                $this->error('Failed to fetch order: ' . $response->status());
                Log::error('SyncSingleOrder: API response failed for basket_id ' . $basketId . ' with status ' . $response->status());
                return;
            }

            $data = $response->json();

            if (empty($data)) {
                $this->warn('API returned empty or invalid data.');
                Log::warning('SyncSingleOrder: Empty response for basket_id ' . $basketId);
                return;
            }

            $this->info('API call successful. Attempting to insert order into DB...');

            $syncService = new OrderSyncService();
            $result = $syncService->createOrder($data);

            if ($result) {
                $this->info("✅ Order $basketId created successfully.");
            } else {
                $this->error("⚠️ Failed to create order $basketId. Check logs.");
            }
        } catch (\Exception $e) {
            Log::error('SyncSingleOrder exception for basket_id ' . $basketId . ': ' . $e->getMessage());
            $this->error('❌ Exception: ' . $e->getMessage());
        }
    }
}
