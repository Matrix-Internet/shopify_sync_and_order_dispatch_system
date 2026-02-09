<?php
namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class OrderSyncService
{
    public function syncOrders()
    {
        $client = new Client();

        $loginResponse = Http::post(config('app.getwaterfit.api_url') . 'suppliers/account/login', [
            'email' => config('app.getwaterfit.api_email'),
            'password' => config('app.getwaterfit.api_password'),
        ]);

        $loginData = json_decode($loginResponse->body(), true);

        if (!isset($loginData['token'])) {
            Log::error('Login error: Access token not found in the login response. Response: ' . $loginResponse->body());
            return [
                'success' => false,
                'message' => 'Access token not found in the login response.',
            ];
        } else {
            Log::info('Login success: Access token retrieved successfully.');
        }

        $accessToken = $loginData['token'];

        $retryCount = 0;
        $maxRetries = 3;
        $ordersResponse = null;

        while ($retryCount < $maxRetries) {
            try {
                $ordersResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->timeout(60)->get(config('app.getwaterfit.api_url') . 'suppliers/api/1.0/getPendingOrders');

                if ($ordersResponse->successful()) {
                    Log::info('Successfully fetched pending orders.', [
                        'status' => $ordersResponse->status(),
                        'response' => $ordersResponse->body(),
                        'query_params' => [
                            'url' => config('app.getwaterfit.api_url') . 'suppliers/api/1.0/getPendingOrders'
                        ],
                    ]);
                    break;
                } else {
                    Log::error('Failed to fetch pending orders. HTTP Status: ' . $ordersResponse->status() . ' Response: ' . $ordersResponse->body());
                }
            } catch (\Exception $e) {
                Log::error('Failed to fetch pending orders. Exception: ' . $e->getMessage());
            }

            $retryCount++;
        }

        if (!$ordersResponse || !$ordersResponse->successful()) {
            Log::error('Max retries reached or unsuccessful response, could not fetch pending orders. Last response: ' . ($ordersResponse ? $ordersResponse->body() : 'No response'));
            return redirect()->back()->with('error', 'SWSM API timed out. Please try again.');
        }

        $data = json_decode($ordersResponse->body(), true);

        if (empty($data) || !is_array($data)) {
            Log::info('Orders API response: No orders to sync to the database. Full response: ' . $ordersResponse->body());
            return redirect()->back()->with('info', 'No orders to sync.');
        } else {
            Log::info('Orders API response contains orders. Processing orders: ' . json_encode($data));
        }

        $count = 0;
        $missingOrders = [];

        foreach ($data as $item) {
            if (!isset($item['basket_id'])) {
                Log::warning('Order data does not contain basket_id. Skipping order: ' . json_encode($item));
                continue;
            }

            Log::info('Processing order with basket_id: ' . $item['basket_id']);
            $existing_order = Order::where('basket_id', $item['basket_id'])->first();
            if ($existing_order) {
                Log::info('Order with basket_id ' . $item['basket_id'] . ' already exists. Skipping.');
                continue;
            }

            $created = $this->createOrder($item);
            if ($created) {
                $count++;
            } else {
                $missingOrders[] = $item['basket_id'];
            }
        }

        // Retry fetching any missing orders individually
        if (count($missingOrders) > 0) {
            Log::info('Attempting to fetch missing orders individually: ' . implode(', ', $missingOrders));
            foreach ($missingOrders as $basketId) {
                try {
                    $individualResponse = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $accessToken,
                    ])->timeout(30)->get(config('app.getwaterfit.api_url') . 'suppliers/api/1.0/order/' . $basketId);

                    if ($individualResponse->successful()) {
                        $individualOrderData = json_decode($individualResponse->body(), true);
                        if ($individualOrderData) {
                            $created = $this->createOrder($individualOrderData);
                            if ($created) {
                                $count++;
                            }
                        } else {
                            Log::warning('Individual order fetch returned empty for basket_id: ' . $basketId);
                        }
                    } else {
                        Log::error('Failed to fetch individual order. HTTP Status: ' . $individualResponse->status() . ' Response: ' . $individualResponse->body());
                    }
                } catch (\Exception $e) {
                    Log::error('Exception occurred while fetching individual order: ' . $e->getMessage());
                }
            }
        }

        if ($count > 0) {
            return redirect()->back()->with('success', $count . ' orders synced successfully.');
        } else {
            return redirect()->back()->with('info', 'Nothing to sync.');
        }
    }

   // private function createOrder($item)
   public function createOrder($item)
    {
        try {
            $order = new Order();
            $order->basket_id = $item['basket_id'] ?? '';
            $order->order_reference = $this->sanitizeField($item['order_reference'] ?? '');
            $order->customer_title = $this->sanitizeField($item['customer']['title'] ?? '');
            $order->customer_forename = $this->sanitizeField($item['customer']['forename'] ?? '');
            $order->customer_surname = $this->sanitizeField($item['customer']['surname'] ?? '');
            $order->customer_house_name_number = $this->sanitizeField($item['customer']['address']['house_name_number'] ?? '');
            $order->customer_line_1 = $this->sanitizeField($item['customer']['address']['line1'] ?? '');
            $order->customer_line_2 = $this->sanitizeField($item['customer']['address']['line2'] ?? '');
            $order->customer_line_3 = $this->sanitizeField($item['customer']['address']['line3'] ?? '');
            $order->customer_county = $this->sanitizeField($item['customer']['address']['county'] ?? '');
            $order->customer_postcode = $this->sanitizeField($item['customer']['address']['postcode'] ?? '');
            $order->customer_telephone = $this->sanitizeField($item['customer']['telephone'] ?? '');
            $order->customer_email = $this->sanitizeEmail($item['customer']['email'] ?? '');
            $order->order_line = $this->sanitizeField($item['order_line'] ?? '');

            if (!filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
                Log::error('Invalid email address for order: ' . $order->order_reference . ', email: ' . $order->customer_email);
                return false;
            }

            $order->save();
            Log::info('New order created: ' . $order->order_reference);

            if (isset($item['products']) && is_array($item['products'])) {
                foreach ($item['products'] as $product) {
                    try {
                        DB::table('product_codes')->insert([
                            'basket_id' => $order->basket_id,
                            'basket_item_id' => $this->sanitizeField($product['basket_item_id'] ?? ''),
                            'code' => $this->sanitizeField($product['code'] ?? ''),
                            'quantity' => $product['quantity'] ?? 0,
                            'stockist' => $this->sanitizeField($product['stockist'] ?? ''),
                        ]);
                        Log::info('Product code inserted for basket_id: ' . $order->basket_id);
                    } catch (\Exception $e) {
                        Log::error('Failed to insert product code for basket_id ' . $order->basket_id . ': ' . $e->getMessage());
                        continue;
                    }
                }
            } else {
                Log::warning('No products found for basket_id: ' . $item['basket_id']);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to create order: ' . $e->getMessage());
            return false;
        }
    }

    public function showOrders()
    {
        $allOrders = Order::orderBy('id', 'desc')->paginate(50);
        return view('dashboard', compact('allOrders'));
    }

    private function sanitizeField($field)
    {
        return trim(preg_replace('/\s+/', ' ', $field));
    }

    private function sanitizeEmail($email)
    {
        $email = trim($email);
        $email = preg_replace('/\s+/', '', $email); // remove spaces
        $email = preg_replace('/(.*\.com).*$/i', '$1', $email); // remove junk after .com
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return $email;
    }
}
