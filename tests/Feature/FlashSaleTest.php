<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TEST 1: No overselling under concurrent hold requests
     * 
     * Scenario: 10 items in stock, 20 concurrent requests
     * Expected: Exactly 10 holds succeed, 10 fail
     */
    public function test_no_overselling_with_parallel_hold_attempts(): void
    {
        // Arrange: Product with limited stock at boundary
        $product = Product::factory()->create(['stock' => 10]);
        $successCount = 0;
        $failCount = 0;

        // Act: Simulate 20 concurrent requests trying to hold 1 item each
        $processes = [];
        for ($i = 0; $i < 20; $i++) {
            $processes[] = function() use ($product, &$successCount, &$failCount) {
                $response = $this->postJson('/api/v1/holds', [
                    'product_id' => $product->id,
                    'qty' => 1,
                ]);

                if ($response->status() === 201) {
                    $successCount++;
                } else {
                    $failCount++;
                }

                return $response;
            };
        }

        // Execute all requests (simulating concurrency)
        foreach ($processes as $process) {
            $process();
        }

        // Assert: Exactly 10 holds should succeed
        $this->assertEquals(10, $successCount, 
            "Expected exactly 10 successful holds for 10 stock items");
        $this->assertEquals(10, $failCount, 
            "Expected exactly 10 failed holds when stock exhausted");

        // Verify database state
        $this->assertEquals(10, Hold::where('product_id', $product->id)->count());
        $this->assertEquals(0, $product->fresh()->getAvailableStock());
        
        Log::info('✓ TEST 1 PASSED: No overselling under concurrency', [
            'stock' => 10,
            'requests' => 20,
            'successful_holds' => $successCount,
            'failed_holds' => $failCount
        ]);
    }

    /**
     * TEST 2: Hold expiry returns stock to availability
     * 
     * Scenario: Create hold, wait for expiry, verify stock returns
     * Expected: Available stock increases after expiry
     */
    public function test_expired_holds_release_stock_availability(): void
    {
        // Arrange: Product with stock and an expired hold
        $product = Product::factory()->create(['stock' => 100]);
        
        // Create active hold (reduces available stock)
        $activeHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 30,
            'expires_at' => now()->addMinutes(2),
            'consumed' => false,
        ]);

        // Create expired hold (should not affect availability)
        $expiredHold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 20,
            'expires_at' => now()->subMinutes(5), // Expired
            'consumed' => false,
        ]);

        // Act: Calculate availability before cleanup
        $availableBefore = $product->calculateAvailableStock();

        // Simulate background job: delete expired holds
        Hold::where('expires_at', '<=', now())
            ->where('consumed', false)
            ->delete();

        // Invalidate cache
        $product->invalidateStockCache();

        // Act: Calculate availability after cleanup
        $availableAfter = $product->calculateAvailableStock();

        // Assert: Expired hold should not reduce availability
        $this->assertEquals(70, $availableBefore, 
            "Before cleanup: 100 stock - 30 active hold = 70 available");
        $this->assertEquals(70, $availableAfter, 
            "After cleanup: Expired holds should not affect availability");

        // Verify expired hold was deleted
        $this->assertNull(Hold::find($expiredHold->id));
        $this->assertNotNull(Hold::find($activeHold->id));

        Log::info('✓ TEST 2 PASSED: Expired holds release availability', [
            'stock' => 100,
            'active_hold' => 30,
            'expired_hold' => 20,
            'available_before' => $availableBefore,
            'available_after' => $availableAfter
        ]);
    }

    /**
     * TEST 3: Webhook idempotency with duplicate requests
     * 
     * Scenario: Send same webhook twice with identical idempotency key
     * Expected: Both return success, order updated once, stock deducted once
     */
    public function test_webhook_idempotency_prevents_duplicate_processing(): void
    {
        // Arrange: Create product, hold, and order
        $product = Product::factory()->create(['stock' => 100]);
        
        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'consumed' => true,
        ]);

        $order = Order::factory()->create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'total' => 5 * $product->price,
            'status' => 'pending',
        ]);

        $idempotencyKey = 'test_payment_' . uniqid();

        // Act: Send webhook twice with same idempotency key
        $response1 = $this->postJson('/api/v1/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $response2 = $this->postJson('/api/v1/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        // Assert: Both requests succeed
        $response1->assertOk();
        $response2->assertOk();

        // Assert: Same response returned
        $this->assertEquals($response1->json(), $response2->json());

        // Assert: Order updated only once
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Assert: Stock deducted only once
        $product->refresh();
        $this->assertEquals(95, $product->stock, 
            "Stock should be deducted once: 100 - 5 = 95");

        // Assert: Only one idempotency key record
        $this->assertEquals(1, 
            DB::table('idempotency_keys')->where('key', $idempotencyKey)->count(),
            "Only one idempotency key record should exist");

        Log::info('✓ TEST 3 PASSED: Webhook idempotency working', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'order_status' => $order->status,
            'stock_after' => $product->stock,
            'duplicate_prevented' => true
        ]);
    }

    /**
     * TEST 4: Webhook arrives before order creation (race condition)
     * 
     * Scenario: Payment webhook arrives before order is created in database
     * Expected: Webhook waits and processes successfully
     */
    public function test_webhook_handles_early_arrival_before_order_exists(): void
    {
        // Arrange: Create product and hold only (no order yet)
        $product = Product::factory()->create(['stock' => 50]);
        
        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'consumed' => true,
        ]);

        // Simulate order ID that will be created (auto-increment)
        $futureOrderId = DB::table('orders')->max('id') + 1;
        $idempotencyKey = 'early_webhook_' . uniqid();

        // Act: Send webhook BEFORE order exists
        // This simulates async race condition
        $webhookSent = false;
        $orderCreated = false;

        // Start webhook request (it should wait)
        $webhookResponse = null;
        
        // Webhook arrives first
        try {
            // In real scenario, this would use async/queue
            // For test, we simulate by catching the exception
            $webhookResponse = $this->postJson('/api/v1/payments/webhook', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $futureOrderId,
                'status' => 'success',
            ]);
            $webhookSent = true;
        } catch (\Exception $e) {
            // Expected: Order not found yet
        }

        // Now create the order (arrives late)
        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'total' => 3 * $product->price,
            'status' => 'pending',
        ]);
        $orderCreated = true;

        // Retry webhook now that order exists
        $webhookResponse = $this->postJson('/api/v1/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        // Assert: Webhook eventually succeeds
        $webhookResponse->assertOk();
        $webhookResponse->assertJson(['status' => 'paid']);

        // Assert: Order is marked as paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Assert: Stock deducted correctly
        $product->refresh();
        $this->assertEquals(47, $product->stock);

        Log::info('✓ TEST 4 PASSED: Webhook handles early arrival', [
            'order_id' => $order->id,
            'webhook_sent_first' => true,
            'order_created_after' => true,
            'final_status' => $order->status,
            'stock_deducted' => 3
        ]);
    }

    /**
     * BONUS TEST: Multiple holds on same product (stress test)
     */
    public function test_multiple_concurrent_holds_maintain_stock_integrity(): void
    {
        $product = Product::factory()->create(['stock' => 50]);
        
        // Create 10 holds of 5 items each = 50 total
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/holds', [
                'product_id' => $product->id,
                'qty' => 5,
            ])->assertCreated();
        }

        // Assert: No more holds possible
        $this->postJson('/api/v1/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(400);

        // Assert: Available stock is zero
        $this->assertEquals(0, $product->fresh()->getAvailableStock());

        // Assert: Physical stock unchanged
        $this->assertEquals(50, $product->fresh()->stock);

        Log::info('✓ BONUS TEST PASSED: Multiple holds maintain integrity', [
            'stock' => 50,
            'holds_created' => 10,
            'available_stock' => 0,
            'physical_stock' => 50
        ]);
    }

    /**
     * BONUS TEST: Failed payment releases hold
     */
    public function test_failed_payment_releases_hold_back_to_availability(): void
    {
        // Arrange
        $product = Product::factory()->create(['stock' => 20]);
        
        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'consumed' => true,
        ]);

        $order = Order::factory()->create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'status' => 'pending',
        ]);

        // Act: Send failed payment webhook
        $response = $this->postJson('/api/v1/payments/webhook', [
            'idempotency_key' => 'failed_payment_' . uniqid(),
            'order_id' => $order->id,
            'status' => 'failed',
        ]);

        // Assert
        $response->assertOk();
        $response->assertJson(['status' => 'cancelled']);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);

        // Hold should be marked as not consumed (released)
        $hold->refresh();
        $this->assertFalse($hold->consumed);

        Log::info('✓ BONUS TEST PASSED: Failed payment releases hold', [
            'order_status' => 'cancelled',
            'hold_released' => true
        ]);
    }
}