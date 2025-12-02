<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ReleaseExpiredHolds implements ShouldQueue
{
    public function handle(): void
    {
        $expired = Hold::where('expires_at', '<=', now())
            ->where('consumed', false)
            ->get();

        foreach ($expired as $hold) {
            DB::transaction(function () use ($hold) {
                $hold->delete();
                $hold->product->invalidateStockCache();

                Log::info('Hold expired and released', [
                    'hold_id' => $hold->id,
                    'product_id' => $hold->product_id,
                    'quantity' => $hold->quantity,
                ]);
            });
        }
    }
}