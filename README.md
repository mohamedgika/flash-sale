# Flash-Sale Checkout System

High-concurrency inventory management for Laravel 12 with zero overselling guarantees.

## Assumptions and Invariants

### Assumptions
1. **Database**: MySQL 8+ with InnoDB engine (row-level locking required)
2. **Hold Duration**: 2-minute window sufficient for checkout completion
3. **Payment Flow**: Asynchronous webhooks confirm payment (may arrive out-of-order)
4. **Stock Model**: Physical inventory only, no backorders or negative stock allowed
5. **Concurrency**: Up to 100 simultaneous requests per product during flash sales

### Invariants Enforced
```
✓ stock >= 0                                    (never oversell)
✓ available_stock = stock - Σ(active_holds)     (holds reduce availability immediately)
✓ consumed_hold → cannot_be_reused              (one order per hold)
✓ expired_hold → released_automatically         (background job cleanup)
✓ idempotency_key → unique_processing           (webhooks processed exactly once)
```

### Concurrency Strategy
- **Pessimistic Locking**: `lockForUpdate()` on all stock checks/changes
- **Database Transactions**: All state changes are atomic
- **Idempotency Keys**: Webhooks store results, return cached responses on retry
- **Cache Invalidation**: 5-second TTL with immediate invalidation on updates

---

## How to Run the App

### 1. Prerequisites
```bash
# Required
PHP 8.2+, MySQL 8+, Composer 2+

# Verify versions
php -v
mysql --version
composer --version
```

### 2. Installation
```bash
# Clone repository
git clone <repo-url> flash-sale
cd flash-sale

# Install dependencies
composer install

# Environment setup
cp .env.example .env
php artisan key:generate

# Configure .env
DB_CONNECTION=mysql
DB_DATABASE=flash_sale
DB_USERNAME=root
DB_PASSWORD=your_password
CACHE_DRIVER=database
QUEUE_CONNECTION=database
```

### 3. Database Setup
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE flash_sale CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
php artisan migrate

# Seed demo data (10 products)
php artisan db:seed
```

### 4. Start Services
```bash
# Terminal 1: Queue worker (required for hold expiry)
php artisan queue:work

# Terminal 2: Development server
php artisan serve
# API available at http://localhost:8000/api
```

### 5. Quick Test
```bash
# Check product availability
curl http://localhost:8000/api/v1/products/1

# Create hold
curl -X POST http://localhost:8000/api/v1/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'

# Expected response:
# {"hold_id": 1, "expires_at": "2025-12-02T14:32:00Z"}
```

---

## How to Run Tests

### Full Test Suite
```bash
php artisan test
```

### Individual Tests
```bash
# Test 1: No overselling under concurrency
php artisan test --filter test_no_overselling_with_parallel_hold_attempts

# Test 2: Hold expiry releases stock
php artisan test --filter test_expired_holds_release_stock_availability

# Test 3: Webhook idempotency
php artisan test --filter test_webhook_idempotency_prevents_duplicate_processing

# Test 4: Webhook arrives before order
php artisan test --filter test_webhook_handles_early_arrival_before_order_exists
```

### Test Coverage

| Test | Scenario | Validates |
|------|----------|-----------|
| **Test 1** | 20 requests for 10 items | Exactly 10 succeed, 10 fail (no overselling) |
| **Test 2** | Expired hold cleanup | Stock returns to availability after expiry |
| **Test 3** | Duplicate webhook (same key) | Processed once, stock deducted once |
| **Test 4** | Webhook before order exists | Handles race condition gracefully |

### Expected Output
```
PASS  Tests\Feature\FlashSaleTest
✓ test no overselling with parallel hold attempts
✓ test expired holds release stock availability
✓ test webhook idempotency prevents duplicate processing
✓ test webhook handles early arrival before order exists

Tests:    4 passed (6 assertions)
Duration: 2.34s
```

---

## Where to See Logs/Metrics

### Application Logs
```bash
# Real-time log monitoring
tail -f storage/logs/laravel.log

# Search for specific events
grep "Hold created" storage/logs/laravel.log
grep "Order created" storage/logs/laravel.log
grep "Webhook processed" storage/logs/laravel.log
```

### Key Log Events

**Hold Creation:**
```
[2025-12-02 14:30:00] local.INFO: Hold created
{
  "hold_id": 123,
  "product_id": 1,
  "quantity": 2,
  "available_stock_remaining": 48
}
```

**Order Creation:**
```
[2025-12-02 14:30:05] local.INFO: Order created
{
  "order_id": 456,
  "hold_id": 123,
  "total": 2399.98
}
```

**Webhook Processing:**
```
[2025-12-02 14:30:10] local.INFO: Webhook processed
{
  "idempotency_key": "pay_abc123",
  "order_id": 456,
  "status": "paid",
  "stock_deducted": 2
}
```

**Duplicate Webhook Detected:**
```
[2025-12-02 14:30:15] local.INFO: Duplicate webhook detected
{
  "idempotency_key": "pay_abc123",
  "cached_response": true
}
```

**Hold Expiry:**
```
[2025-12-02 14:32:00] local.INFO: Hold expired and released
{
  "hold_id": 789,
  "product_id": 1,
  "quantity": 2
}
```

### Database Metrics

**Current Stock Status:**
```sql
-- Check product inventory
SELECT 
  id,
  name,
  stock,
  (SELECT SUM(quantity) FROM holds 
   WHERE product_id = products.id 
   AND expires_at > NOW() 
   AND consumed = false) as held_stock,
  stock - COALESCE((SELECT SUM(quantity) FROM holds 
                    WHERE product_id = products.id 
                    AND expires_at > NOW() 
                    AND consumed = false), 0) as available_stock
FROM products;
```

**Active Holds:**
```sql
SELECT COUNT(*), SUM(quantity) 
FROM holds 
WHERE expires_at > NOW() AND consumed = false;
```

**Order Status Distribution:**
```sql
SELECT status, COUNT(*), SUM(total) 
FROM orders 
GROUP BY status;
```

**Idempotency Key Usage:**
```sql
SELECT COUNT(*) as total_webhooks,
       COUNT(DISTINCT order_id) as unique_orders
FROM idempotency_keys;
```

### Performance Metrics

**Query Performance:**
```bash
# Enable query logging in .env
DB_LOG_QUERIES=true

# Check slow queries
grep "Slow query" storage/logs/laravel.log
```

**Cache Hit Rate:**
```bash
grep "product:.*:available_stock" storage/logs/laravel.log | wc -l
```

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/products/{id}` | Get product + availability |
| POST | `/api/v1/holds` | Create temporary hold (2 min) |
| POST | `/api/v1/orders` | Convert hold to order |
| POST | `/api/v1/payments/webhook` | Payment confirmation (idempotent) |

---

## Troubleshooting

**Problem:** Holds not expiring  
**Solution:** Ensure queue worker is running: `php artisan queue:work`

**Problem:** "Insufficient stock" but UI shows available  
**Solution:** Clear cache: `php artisan cache:clear`

**Problem:** Tests failing with "Database not found"  
**Solution:** Create test database: `php artisan migrate --env=testing`

---

## Project Structure
```
app/
├── Models/
│   ├── Product.php      # Stock management + cache
│   ├── Hold.php         # Temporary reservations
│   └── Order.php        # Finalized purchases
├── Http/Controllers/
│   ├── ProductController.php
│   ├── HoldController.php      # Critical: lockForUpdate()
│   ├── OrderController.php
│   └── WebhookController.php   # Idempotency logic
└── Jobs/
    └── ReleaseExpiredHolds.php # Background cleanup

database/
├── migrations/
│   ├── *_create_products_table.php
│   ├── *_create_holds_table.php
│   ├── *_create_orders_table.php
│   └── *_create_idempotency_keys_table.php
└── seeders/
    └── DatabaseSeeder.php       # 10 demo products

tests/
└── Feature/
    └── FlashSaleTest.php        # All 4 required tests
```

---

**Production Checklist:**
- [ ] Switch `CACHE_DRIVER=redis`
- [ ] Add webhook signature verification
- [ ] Enable query logging for monitoring
- [ ] Set up queue worker monitoring (Supervisor)
- [ ] Configure rate limiting on hold endpoint
- [ ] Add index on `holds(expires_at, consumed)` if not exists
- [ ] Set up alerting for failed jobs