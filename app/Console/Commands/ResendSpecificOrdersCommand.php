<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CreateOrderService;

class ResendSpecificOrdersCommand extends Command
{
    protected $signature = 'orders:resend {orderReferences*}';
    protected $description = 'Resend specific orders to Shopify';

    private $createOrderService;

    public function __construct(CreateOrderService $createOrderService)
    {
        parent::__construct();
        $this->createOrderService = $createOrderService;
    }

    public function handle()
    {
        $orderReferences = $this->argument('orderReferences');

        if (empty($orderReferences)) {
            $this->error('No order references provided.');
            return;
        }

        $this->info('Resending orders: ' . implode(', ', $orderReferences));

        try {
            $this->createOrderService->sendOrdersToShopify($orderReferences);
            $this->info('Orders have been resent successfully.');
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            \Log::error('Error resending orders: ' . $e->getMessage());
        }
    }
}
