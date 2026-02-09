<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShopifyFulfillmentService;

class FulfillSpecificOrdersCommand extends Command
{
    protected $signature = 'orders:fulfill {basket_ids*}';
    protected $description = 'Manually fulfill specific orders by basket IDs';

    private $shopifyFulfillmentService;

    public function __construct(ShopifyFulfillmentService $shopifyFulfillmentService)
    {
        parent::__construct();
        $this->shopifyFulfillmentService = $shopifyFulfillmentService;
    }

    public function handle()
    {
        $basketIds = $this->argument('basket_ids');

        $this->info('Processing fulfillments for basket IDs: ' . implode(', ', $basketIds));

        $this->shopifyFulfillmentService->fulfillSpecificOrders($basketIds);

        $this->info('Fulfillment processing completed.');
    }
}
