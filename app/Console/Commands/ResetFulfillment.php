<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Fulfillment;

class ResetFulfillment extends Command
{
    protected $signature = 'fulfillment:reset {basket_id}';
    protected $description = 'Reset dispatched flags for a fulfillment by basket_id so it can be re-sent to GetWaterFit';

    public function handle()
    {
        $basketId = $this->argument('basket_id');

        // 1. find the order
        $order = Order::where('basket_id', $basketId)->first();

        if (!$order) {
            $this->error("Order with basket_id {$basketId} not found.");
            return Command::FAILURE;
        }

        // 2. find fulfillments for that order
        $fulfillments = Fulfillment::where('order_id', $order->id)->get();

        if ($fulfillments->isEmpty()) {
            $this->warn("No fulfillment records found for basket_id {$basketId} (order id {$order->id}).");
            return Command::SUCCESS;
        }

        // 3. reset them
        foreach ($fulfillments as $fulfillment) {
            $fulfillment->dispatched = false;
            $fulfillment->dispatched_at = null;
            $fulfillment->save();
        }

        $this->info("Fulfillment(s) for basket_id {$basketId} reset. Re-run your sync to push dispatch again.");

        return Command::SUCCESS;
    }
}
