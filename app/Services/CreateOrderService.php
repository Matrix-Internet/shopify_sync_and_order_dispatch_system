<?php

/**
 * @Author: Bernard Hanna
 * @Date:   2023-06-02 15:14:54
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-09-12 12:12:32
 */

namespace App\Services;

use App\Models\Order;
use App\Models\ProductCode;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class CreateOrderService
{
    public function sendOrdersToShopify($startOrderReference = null, $endOrderReference = null)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(1000);

        Log::info('Starting sendOrdersToShopify...');
 //EDIT REFACTORED ON JUNE 5 2025
        // Update customer_telephone with default value '00000000000' where it's empty
         //Order::whereNull('customer_telephone')
          //   ->orWhere('customer_telephone', '')
         //    ->update(['customer_telephone' => '00000000000']);

        $ordersSent = false;
        $maxOrders = 100;

        try {
            $query = Order::where(function($q) {
                $q->where('sent_to_shopify', 0)
                ->orWhereNull('sent_to_shopify');
            })->where('rejected', 0);

            if ($startOrderReference && $endOrderReference) {
                Log::info('Filtering orders between: ' . $startOrderReference . ' and ' . $endOrderReference);
                $query->whereBetween('order_reference', [$startOrderReference, $endOrderReference]);
            }

           
                // Retrieve up to $maxOrders orders that have not been sent to Shopify
                $orders = $query->orderBy('order_reference', 'asc')->limit($maxOrders)->get();
                Log::info('Number of orders retrieved: ' . $orders->count());

                if ($orders->isEmpty()) {
                    Log::info('No more orders found to send.');
                   
                }

                foreach ($orders as $order) {
                    Log::info('Processing order: ' . $order->order_reference);

                    if (empty($order->customer_telephone)) {
                        $order->customer_telephone = '00000000000';
                    } else {
                        $order->customer_telephone = $this->sanitizeField($order->customer_telephone);
                    }

                    // Use a transaction to avoid partial updates
                    \DB::beginTransaction();

                    try {
                        $existingOrder = Order::where('order_reference', $order->order_reference)
                            ->where('sent_to_shopify', true)
                            ->first();

                        if ($existingOrder) {
                            $order->rejected = true;
                            $order->save();
                            Log::info('Order with order_reference ' . $order->order_reference . ' already sent. Marked as rejected.');
                            continue;
                        }

                        $lineItems = [];
                        $allLineItemsValid = true;

                        // Sanitize email and phone fields
                        $order->customer_email = $this->sanitizeEmail($order->customer_email);
                        $order->customer_telephone = $this->sanitizeField($order->customer_telephone);

                        // Validate the email format
                        if (!filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
                            Log::error('Invalid email address for order: ' . $order->order_reference . ', email: ' . $order->customer_email);
                            // Mark the order as rejected to avoid retries
                            $order->rejected = true;
                            $order->save();
                            \DB::commit(); // Commit the rejection and continue
                            continue;
                        }

                        // Define metafields
                        $metafields = $this->prepareMetafields($order);

                        // Load the line items for this order
                        $order->load('productCodes');
                        $productCodesGrouped = $order->productCodes->groupBy('code');

                        foreach ($productCodesGrouped as $code => $productCodes) {
                            $productCode = ProductCode::where('code', $code)->first();

                            if (!$productCode) {
                                Log::error('Product code not found: ' . $code);
                                $this->sendMissingSkuNotification($order);
                                $allLineItemsValid = false;
                                continue;
                            }

                            $variant = Variant::where('sku', $code)->first();

                            if (!$variant) {
                                Log::error('Variant not found for SKU: ' . $code);
                                $allLineItemsValid = false;
                                continue;
                            }

                            Log::info('Processing variant ' . $variant->id . ', SKU: ' . $variant->sku);

                            $product = $variant->product;

                            if (!$product) {
                                Log::error('Product not found for variant: ' . $variant->id);
                                $allLineItemsValid = false;
                                continue;
                            }

                            if (!isset($product->status) || $product->status !== 'active') {
                                $this->sendInactiveProductNotification($order, $productCodes);
                                $allLineItemsValid = false;
                                continue;
                            }

                            if (!$this->isVariantActiveOnShopify($variant)) {
                                $allLineItemsValid = false;
                                continue;
                            }

                            $totalQuantity = $productCodes->sum('quantity');
                            $lineItems[] = $this->prepareLineItem($variant, $productCodes, $order->order_line, $totalQuantity);
                        }

                        if (!$allLineItemsValid || empty($lineItems)) {
                            $this->sendInvalidLineItemNotification($order);
                            continue;
                        }

                        $order_data = $this->prepareOrderData($order, $lineItems, $metafields);

                        Log::info('Sending order request: ' . json_encode($order_data));
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'X-Shopify-Access-Token' => config('app.shopify.access_token'),
                        ])->post($this->getOrderEndpoint(), $order_data);

                        Log::info('Order response: ' . $response->body());

                    // After checking if the response failed
                    if ($response->failed()) {
                         $this->handleFailedResponse($order, $response);
    
                        // Mark as rejected to avoid endless retries
                        $order->rejected = true;
                        $order->save();

                        \DB::rollBack();
                        Log::warning('Continuing to next order after failure for: ' . $order->order_reference);
                        continue;
                    }

                    // Set sent_to_shopify to true immediately after successful response
                    $order->sent_to_shopify = true;
                    $order->save();
                    Log::info('Order has been sent to Shopify successfully: Order ID ' . $order->basket_id);
                    $ordersSent = true;

                    // Commit the transaction if everything is fine
                    \DB::commit();
                    } catch (Exception $e) {
                        // Rollback the transaction in case of an exception
                        \DB::rollBack();
                        Log::error('Failed to send order to Shopify: ' . $e->getMessage());

                        // Optional: mark as rejected if you want
                        $order->rejected = true;
                        $order->save();

                        Log::warning('Continuing to next order after exception for: ' . $order->order_reference);

                        // Correct: CONTINUE instead of BREAK
                        continue;
                    }
                }
    

        } catch (Exception $e) {
            Log::error('An error occurred while sending orders to Shopify: ' . $e->getMessage());
        }

        $this->handlePostProcessing($ordersSent, $orders);
        return redirect()->back();
    }

    private function sanitizeField($field)
    {
        return trim(preg_replace('/\s+/', ' ', $field));
    }

    private function sanitizeEmail($email)
    {
        $email = trim(preg_replace('/\s+/', '', $email)); // Remove spaces
        $email = filter_var($email, FILTER_SANITIZE_EMAIL); // Sanitize email

        // Check if email has unexpected characters (e.g., ending with "0")
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::error('Invalid email format detected: ' . $email);
            return null; // Return null or handle invalid email accordingly
        }

        return $email;
    }

    private function prepareMetafields(Order $order)
    {
        return [
            [
                'namespace' => 'global',
                'key' => 'order_reference',
                'value' => $order->order_reference,
                'type' => 'string'
            ],
            [
                'namespace' => 'global',
                'key' => 'telephone',
                'value' => $order->customer_telephone,
                'type' => 'string'
            ],
            [
                'namespace' => 'global',
                'key' => 'order_line',
                'value' => !empty($order->order_line) ? $order->order_line : 'default-tag',
                'type' => 'string'
            ]
        ];
    }

    private function prepareLineItem(Variant $variant, $productCodes, $orderLine, $totalQuantity)
    {
        return [
            'variant_id' => $variant->id,
            'inventory_item_id' => $variant->inventory_item_id,
            'product_id' => $variant->product->id,
            'variant_title' => '(Basket Item ID: ' . $productCodes->pluck('basket_item_id')->implode(', ') . ')',
            'title' => $variant->product->title,
            'price' => 0.00, // Replace with the actual price
            'quantity' => $totalQuantity,
            'vendor' => $variant->product->vendor,
            'sku' => $variant->sku,
            'properties' => [
                [
                    'name' => 'Channel',
                    'value' => $orderLine
                ]
            ]
        ];
    }

    private function prepareOrderData(Order $order, array $lineItems, array $metafields)
    {
        return [
            'order' => [
                'note' => $order->basket_id,
                'name' => $order->order_reference,
                'order_number' => $order->basket_id,
                'tags' => !empty($order->order_line) ? $order->order_line : 'default-tag',
                'inventory_behaviour' => 'decrement_ignore',
                'financial_status' => 'paid',
                'fulfillment_status' => 'unfulfilled',
                'customer' => [
                    'first_name' => $order->customer_forename,
                    'last_name' => $order->customer_surname,
                    'email' => $order->customer_email, // Use the sanitized email here
                ],
                'billing_address' => $this->prepareAddress($order),
                'shipping_address' => $this->prepareAddress($order),
                'line_items' => $lineItems,
                'metafields' => $metafields,
            ],
        ];
    }

    private function prepareAddress(Order $order)
    {
        return [
            'first_name' => $order->customer_forename,
            'last_name' => $order->customer_surname,
            'address1' => !empty(trim($order->customer_house_name_number . $order->customer_line_1)) ? $order->customer_house_name_number . ', ' . $order->customer_line_1 : 'N/A',
            'address2' => !empty($order->customer_line_2) ? $order->customer_line_2 : 'N/A',
            'city' => !empty($order->customer_line_3) ? $order->customer_line_3 : 'N/A',
            'phone' => ltrim($order->customer_telephone, '+'),
            'province' => 'CA',
            'country' => 'GB',
            'zip' => !empty($order->customer_postcode) ? $order->customer_postcode : 'N/A',
        ];
    }

    private function getOrderEndpoint()
    {
        return 'https://' . config('app.shopify.domain') . '/admin/api/' . config('app.shopify.api_version') . '/orders.json';
    }

    private function sendMissingSkuNotification(Order $order)
    {
        $emailData = [
            'order_number' => $order->basket_id,
            'line_items' => $order->productCodes->toArray(),
        ];
        \Mail::to(config('app.admin_email'))->send(new \App\Mail\MissingSkuEmail($emailData));
    }

    private function sendInactiveProductNotification(Order $order, $productCodes)
    {
        $emailData = [
            'orderNumber' => $order->basket_id,
            'lineItems' => $productCodes->toArray(),
        ];
        \Mail::to(config('app.admin_email'))->send(new \App\Mail\OrderErrorInactiveProducts($emailData['orderNumber'], $emailData['lineItems']));
    }

    private function sendInvalidLineItemNotification(Order $order)
    {
        $errorMessage = 'Order number ' . $order->basket_id . ' was not sent because of invalid line items.';
        $emailData = [
            'order_number' => $order->basket_id,
            'line_items' => $order->productCodes->toArray(),
            'error_message' => $errorMessage,
        ];
       // \Mail::to(config('app.admin_email'))->send(new \App\Mail\OrderErrorMail($emailData));
       \Mail::to(config('app.admin_email'))->send(new \App\Mail\OrderErrorMail($emailData, 'InvalidLineItem'));
    }

    private function handleFailedResponse(Order $order, $response)
    {
        $error_msg = 'HTTP error: ' . $response->status() . ' ' . $response->body();
        Log::error($error_msg);

        $errorMessage = 'Order number ' . $order->basket_id . ' was not sent due to an error.';
        $emailData = [
            'order_number' => $order->basket_id,
            'line_items' => $order->productCodes->toArray(),
            'error_message' => $errorMessage,
        ];
        \Mail::to(config('app.admin_email'))->send(new \App\Mail\GenericErrorEmail($emailData));
    }

    private function handlePostProcessing(bool $ordersSent, $orders)
    {
        if ($ordersSent) {
            session()->flash('success', 'Orders have been sent to Shopify');
            \Mail::to(config('app.admin_email'))->send(new \App\Mail\NewOrderMail($orders));
        } else {
            session()->flash('info', 'No orders were sent');
            \Mail::to(config('app.admin_email'))->send(new \App\Mail\NewOrderMail([]));
        }
    }

    private function isVariantActiveOnShopify(Variant $variant): bool
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => config('app.shopify.access_token'),
            ])->get('https://' . config('app.shopify.domain') . '/admin/api/' . config('app.shopify.api_version') . '/variants/' . $variant->id . '.json');

            if ($response->failed()) {
                Log::error('Failed to retrieve variant status from Shopify: ' . $response->status() . ' ' . $response->body());
                return false;
            }

            $variantData = $response->json();

            if (isset($variantData['variant']['status']) && $variantData['variant']['status'] !== 'active') {
                Log::info('Skipping variant ' . $variant->id . ' because its status is not active on Shopify.');
                return false;
            }

            if (isset($variantData['variant']['product_id'])) {
                $product = Product::find($variantData['variant']['product_id']);
                if ($product && !$this->isProductActiveOnShopify($product)) {
                    Log::info('Skipping variant ' . $variant->id . ' because its associated product is not active on Shopify.');
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            Log::error('Exception during variant status check: ' . $e->getMessage());
            return false;
        }
    }

    private function isProductActiveOnShopify(Product $product): bool
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => config('app.shopify.access_token'),
            ])->get('https://' . config('app.shopify.domain') . '/admin/api/' . config('app.shopify.api_version') . '/products/' . $product->id . '.json');

            if ($response->failed()) {
                Log::error('Failed to retrieve product status from Shopify: ' . $response->status() . ' ' . $response->body());
                return false;
            }

            $productData = $response->json();

            if (isset($productData['product']['status']) && $productData['product']['status'] !== 'active') {
                Log::info('Skipping product ' . $product->id . ' because its status is not active on Shopify.');
                return false;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Exception during product status check: ' . $e->getMessage());
            return false;
        }
    }
}
