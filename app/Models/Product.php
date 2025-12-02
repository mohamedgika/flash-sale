<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Hold;


class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'price', 'stock'];
    public function holds()
    {
        return $this->hasMany(Hold::class);
    }
    public function getAvailableStock(): int
    {
        return Cache::remember(
            "product:{$this->id}:available_stock",
            now()->addSeconds(5),
            fn() => $this->calculateAvailableStock()
        );
    }
    public function calculateAvailableStock(): int
    {
        $activeHolds = $this->holds()
            ->where('expires_at', '>', now())
            ->where('consumed', false)
            ->sum('quantity');

        return max(0, $this->stock - $activeHolds);
    }

    public function invalidateStockCache(): void
    {
        Cache::forget("product:{$this->id}:available_stock");
    }
}
