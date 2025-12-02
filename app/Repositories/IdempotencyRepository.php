<?php

namespace App\Repositories;

use App\Interfaces\IdempotencyRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\Product;
use App\Models\Hold;

class IdempotencyRepository implements IdempotencyRepositoryInterface
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string',
            'order_id' => 'required|exists:orders,id',
            'status' => 'required|in:success,failed',
        ]);

        try {
            // Check for existing idempotency key
            $existing = DB::table('idempotency_keys')
                ->where('key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                Log::info('Duplicate webhook detected', [
                    'key' => $validated['idempotency_key']
                ]);
                return response()->json(json_decode($existing->response), 200);
            }

            $response = DB::transaction(function () use ($validated) {
                // Insert idempotency key first (prevents race)
                DB::table('idempotency_keys')->insert([
                    'key' => $validated['idempotency_key'],
                    'order_id' => $validated['order_id'],
                    'created_at' => now(),
                ]);

                // Wait briefly for order to be created if needed
                $order = Order::where('id', $validated['order_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    // Order might not exist yet, retry logic here
                    sleep(1);
                    $order = Order::where('id', $validated['order_id'])
                        ->lockForUpdate()
                        ->first();
                }

                if ($validated['status'] === 'success') {
                    $order->status = 'paid';
                    $order->save();

                    // Deduct from actual stock
                    $product = Product::lockForUpdate()->find($order->product_id);
                    $product->stock -= $order->quantity;
                    $product->save();
                    $product->invalidateStockCache();

                    $result = ['status' => 'paid'];
                } else {
                    $order->status = 'cancelled';
                    $order->save();

                    // Release stock by marking hold as available again
                    $hold = Hold::find($order->hold_id);
                    $hold->consumed = false;
                    $hold->save();

                    $hold->product->invalidateStockCache();

                    $result = ['status' => 'cancelled'];
                }

                // Store response
                DB::table('idempotency_keys')
                    ->where('key', $validated['idempotency_key'])
                    ->update(['response' => json_encode($result)]);

                Log::info('Webhook processed', [
                    'key' => $validated['idempotency_key'],
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);

                return $result;
            });

            return response()->json($response, 200);

        } catch (\Exception $e) {
            Log::error('Webhook failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}