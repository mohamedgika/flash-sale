<?php

namespace App\Repositories;

use App\Interfaces\OrderRepositoryInterface;
use App\Models\Order;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;


class OrderRepository implements OrderRepositoryInterface
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'hold_id' => 'required|exists:holds,id',
        ]);

        try {
            $order = DB::transaction(function () use ($validated) {
                $hold = Hold::with('product')
                    ->where('id', $validated['hold_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$hold->isValid()) {
                    throw new \Exception('Hold is invalid or expired');
                }

                // Check if already used
                if (Order::where('hold_id', $hold->id)->exists()) {
                    throw new \Exception('Hold already used');
                }

                // Mark hold as consumed
                $hold->consumed = true;
                $hold->save();

                // Create order
                $order = Order::create([
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                    'total' => $hold->quantity * $hold->product->price,
                    'status' => 'pending',
                ]);

                Log::info('Order created', ['order_id' => $order->id]);

                return $order;
            });

            return response()->json([
                'order_id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}