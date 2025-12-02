<?php

namespace App\Repositories;

use App\Interfaces\HoldRepositoryInterface;
use App\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class HoldRepository implements HoldRepositoryInterface
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        try {
            $hold = DB::transaction(function () use ($validated) {
                // Lock the hold row for update
                $product = Product::where('id', $validated['product_id'])
                    ->lockForUpdate()
                    ->first();

                // Check available stock
                $available = $product->calculateAvailableStock();

                if ($available < $validated['qty']) {
                    throw new \Exception('Insufficient stock');
                }

                // Create hold
                $hold = Hold::create([
                    'product_id' => $product->id,
                    'quantity' => $validated['qty'],
                    'expires_at' => now()->addMinutes(2),
                ]);

                // Invalidate cache
                $product->invalidateStockCache();

                Log::info('Hold created', [
                    'hold_id' => $hold->id,
                    'product_id' => $product->id,
                    'quantity' => $validated['qty'],
                ]);

                return $hold;
            });

            return response()->json([
                'message' => 'Hold created successfully',
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at->toIso8601String(),
            ], 201,['Content-Type' => 'application/json']);

        } catch (\Exception $e) {
            Log::error('Hold creation failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}