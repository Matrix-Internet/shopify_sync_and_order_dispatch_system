<?php
namespace App\Services;

use App\Models\Order;
use App\Models\ProductCode;
use Illuminate\Support\Facades\Log;

class GarbageCollectionService
{
    public function performGarbageCollection()
    {
        Log::info('Garbage collection process started');

        // Delete orders and product_codes that are over 90 days old.
        $cutoffDate = now()->subDays(90);
        $deletedOrders = Order::where('created_at', '<', $cutoffDate)->delete();
        $deletedProductCodes = ProductCode::where('created_at', '<', $cutoffDate)->delete();

        Log::info('Garbage collection process finished', [
            'deletedOrders' => $deletedOrders,
            'deletedProductCodes' => $deletedProductCodes
        ]);
    }
}