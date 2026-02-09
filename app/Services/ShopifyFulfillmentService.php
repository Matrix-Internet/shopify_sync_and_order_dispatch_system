<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Models\Order;
use App\Models\Fulfillment;
use App\Mail\FulfillmentOrdersMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ShopifyFulfillmentService
{
    private $shopifyConfigService;

    public function __construct(ShopifyConfigService $shopifyConfigService)
    {
        $this->shopifyConfigService = $shopifyConfigService;
    }

    public function syncFulfillments()
    {
        try {
            $shop = $this->shopifyConfigService->getShop();
            $token = $this->shopifyConfigService->getToken();
            $apiVersion = $this->shopifyConfigService->getApiVersion();

            $client = new Client();

            $params = [
                'status' => 'any',
                'limit' => 250,
                'order' => 'id asc',
                'updated_at_min' => now()->subDays(7)->toIso8601String(),
            ];

            $hasMoreOrders = true;
            $page = 1;

            $fulfilledOrdersCount = 0;
            $fulfilledOrders = [];

            while ($hasMoreOrders) {
                Log::info("Fetching page {$page} of orders");

                $response = $client->request(
                    'GET',
                    "https://{$shop}/admin/api/{$apiVersion}/orders.json",
                    [
                        'headers' => ['X-Shopify-Access-Token' => $token],
                        'query' => $params,
                    ]
                );

                Log::info('Shopify API request', [
                    'method' => 'GET',
                    'url' => "https://{$shop}/admin/api/{$apiVersion}/orders.json",
                    'params' => $params,
                    'headers' => ['X-Shopify-Access-Token' => '******'],
                ]);

                $responseData = json_decode($response->getBody(), true);

                if (!isset($responseData['orders']) || empty($responseData['orders'])) {
                    Log::info('No more orders retrieved from Shopify.');
                    break;
                }

                $ordersBatch = $responseData['orders'];

                $orderIds = array_map(function ($order) {
                    return $order['id'];
                }, $ordersBatch);

                Log::info("Shopify Orders Retrieved on page {$page}", ['order_ids' => $orderIds]);

                foreach ($ordersBatch as $shopifyOrder) {
                    $basketId = $shopifyOrder['note'] ?? null;

                    if (!$basketId) {
                        Log::warning('Basket ID not found in order note', ['shopify_order_id' => $shopifyOrder['id']]);
                        continue;
                    }

                    $order = Order::where('basket_id', $basketId)->first();

                    if (!$order) {
                        Log::warning('Order not found in database for basket_id', ['basket_id' => $basketId, 'shopify_order_id' => $shopifyOrder['id']]);
                        continue;
                    }

                    $order->shopify_order_id = $shopifyOrder['id'];
                    $order->shopify_order_number = $shopifyOrder['name'];
                    $order->save();

                    $this->processFulfillmentForOrder($order, $shopifyOrder);
                }

                $lastOrder = end($ordersBatch);
                $params['since_id'] = $lastOrder['id'];
                $page++;

                if (count($ordersBatch) < 250) {
                    $hasMoreOrders = false;
                }
            }

            Log::info('Total Shopify Orders Processed', ['count' => $fulfilledOrdersCount]);

            if ($fulfilledOrdersCount > 0) {
                Mail::to(config('app.admin_email'))->send(new FulfillmentOrdersMail($fulfilledOrders));
                return redirect()->back()->with('success', $fulfilledOrdersCount . ' orders updated successfully.');
            } else {
                return redirect()->back()->with('info', 'No orders to update.');
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync orders due to a server error.', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to sync orders due to a server error.');
        }
    }

    public function fulfillSpecificOrders(array $basketIds)
    {
        try {
            $shop = $this->shopifyConfigService->getShop();
            $token = $this->shopifyConfigService->getToken();
            $apiVersion = $this->shopifyConfigService->getApiVersion();
            $client = new Client();

            foreach ($basketIds as $basketId) {
                Log::info('Processing fulfillment for basket_id', ['basket_id' => $basketId]);

                $order = Order::where('basket_id', $basketId)->first();

                if (!$order) {
                    Log::warning('Order not found in database for basket_id', ['basket_id' => $basketId]);
                    continue;
                }

                $shopifyOrderId = $order->shopify_order_id;

                if (!$shopifyOrderId) {
                    Log::warning('Shopify order ID not found for basket_id', ['basket_id' => $basketId]);
                    continue;
                }

                try {
                    $response = $client->request(
                        'GET',
                        "https://{$shop}/admin/api/{$apiVersion}/orders/{$shopifyOrderId}.json",
                        [
                            'headers' => ['X-Shopify-Access-Token' => $token],
                        ]
                    );

                    $shopifyOrderData = json_decode($response->getBody(), true);
                    $shopifyOrder = $shopifyOrderData['order'] ?? null;

                    if (!$shopifyOrder) {
                        Log::warning('Shopify order not found for shopify_order_id', ['shopify_order_id' => $shopifyOrderId]);
                        continue;
                    }

                    // original behaviour
                    $this->processFulfillmentForOrder($order, $shopifyOrder);

                    // fallback if Shopify doesn’t send fulfillments
                    if (empty($shopifyOrder['fulfillments'])) {
                        $this->dispatchLocalFulfillments($order);
                    }

                } catch (\Exception $e) {
                    Log::error('Error retrieving Shopify order', [
                        'shopify_order_id' => $shopifyOrderId,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error fulfilling specific orders', ['error' => $e->getMessage()]);
        }
    }

    protected function processFulfillmentForOrder($order, $shopifyOrder)
    {
        try {
            if (empty($shopifyOrder['fulfillments'])) {
                Log::info('No fulfillments found for Shopify order', ['shopify_order_id' => $shopifyOrder['id']]);
                return;
            }

            Log::info('Processing Shopify Order', [
                'shopify_order_id' => $shopifyOrder['id'],
                'name' => $shopifyOrder['name'],
                'fulfillment_status' => $shopifyOrder['fulfillment_status'],
            ]);

            $order->fulfilled = true;

            foreach ($shopifyOrder['fulfillments'] as $shopifyFulfillment) {
                $fulfillment = Fulfillment::updateOrCreate(
                    ['shopify_fulfillment_id' => $shopifyFulfillment['id']],
                    [
                        'order_id' => $order->id,
                        'tracking_number' => $shopifyFulfillment['tracking_number'] ?? null,
                        'tracking_url' => $shopifyFulfillment['tracking_url'] ?? null,
                        'carrier_name' => $shopifyFulfillment['tracking_company'] ?? null,
                        'carrier_code' => $shopifyFulfillment['tracking_company'] ?? null,
                    ]
                );

                if ($fulfillment->dispatched_at === null) {
                    Log::debug('About to dispatch fulfillment', ['fulfillment_id' => $fulfillment->id]);

                    $fulfillment->dispatched = true;
                    $fulfillment->dispatched_at = now();
                    $fulfillment->save();
                    Log::debug('Fulfillment marked as dispatched', ['fulfillment' => $fulfillment]);

                    $this->sendDispatchRequest($order, $fulfillment);
                }
            }

            $order->shopify_order_id = $shopifyOrder['id'];
            $order->shopify_order_number = $shopifyOrder['name'];
            $order->save();

            Log::info('Order marked as fulfilled', ['order_id' => $order->id]);

        } catch (\Exception $e) {
            Log::error('Error processing fulfillment for order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fallback when Shopify doesn't send fulfillments
     */
    protected function dispatchLocalFulfillments(Order $order)
    {
        $fulfillments = Fulfillment::where('order_id', $order->id)->get();

        if ($fulfillments->isEmpty()) {
            Log::warning('Fallback dispatch: no local fulfillments found', [
                'order_id' => $order->id,
            ]);
            return;
        }

        foreach ($fulfillments as $fulfillment) {
            $fulfillment->dispatched = true;
            $fulfillment->dispatched_at = now();
            $fulfillment->carrier_code = $fulfillment->carrier_code ?: 'Other';
            $fulfillment->carrier_name = $fulfillment->carrier_name ?: 'Other';
            $fulfillment->save();

            $this->sendDispatchRequest($order, $fulfillment);
        }

        $order->fulfilled = true;
        $order->save();
    }

    /**
     * NEW: get a fresh token just like OrderSyncService
     */
    protected function getFreshGetWaterFitToken()
    {
        $loginResponse = Http::post(
            config('app.getwaterfit.api_url') . 'suppliers/account/login',
            [
                'email' => config('app.getwaterfit.api_email'),
                'password' => config('app.getwaterfit.api_password'),
            ]
        );

        $loginData = json_decode($loginResponse->body(), true);

        if (!isset($loginData['token'])) {
            Log::error('GetWaterFit login failed in fulfillment: ' . $loginResponse->body());
            return null;
        }

        return $loginData['token'];
    }

    protected function sendDispatchRequest($order, $fulfillment)
    {
        try {
            // get fresh token first
            $token = $this->getFreshGetWaterFitToken();
            if (!$token) {
                // rollback dispatch flags because we didn’t actually send it
                $fulfillment->dispatched = false;
                $fulfillment->dispatched_at = null;
                $fulfillment->save();
                return;
            }

            $client = new Client();
            $url = config('app.getwaterfit.api_url') . "suppliers/api/1.0/dispatchOrder/{$order->basket_id}";
            $order->load('productCodes');

            $productCodes = $order->productCodes->map(function ($productCode) {
                return [
                    "code" => $productCode->code,
                    "quantity" => $productCode->quantity,
                    "basket_item_id" => $productCode->basket_item_id,
                ];
            });

            $data = [
                "order_reference" => $order->order_reference,
                "despatch_number" => strval($fulfillment->tracking_number),
                "product_codes" => $productCodes,
                "quantity" => $order->productCodes->sum('quantity'),
                "despatch_date" => optional($fulfillment->dispatched_at)->format('Y-m-d'),
                "carrier_code" => $fulfillment->carrier_code,
                "carrier_name" => $fulfillment->carrier_name,
                "tracking_number" => $fulfillment->tracking_number,
                "ship-method" => $order->fulfilled ? 'fulfilled' : 'unfulfilled',
            ];

            Log::info('Dispatch request prepared', ['url' => $url, 'payload' => $data]);

            $response = $client->request('PUT', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json' => $data,
            ]);

            $responseBody = json_decode($response->getBody(), true);
            Log::debug('Dispatch response received', ['response' => $responseBody]);

            if ($response->getStatusCode() != 200 || ($responseBody['message'] ?? null) != 'success') {
                $fulfillment->dispatched = false;
                $fulfillment->dispatched_at = null;
                $fulfillment->save();
                Log::warning('Dispatch request failed', [
                    'fulfillment_id' => $fulfillment->id,
                    'status' => $response->getStatusCode(),
                    'body' => $responseBody,
                ]);
            }
        } catch (\Exception $e) {
            $fulfillment->dispatched = false;
            $fulfillment->dispatched_at = null;
            $fulfillment->save();

            Log::error('Error sending dispatch request', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
