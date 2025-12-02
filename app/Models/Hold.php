<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Hold extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'quantity', 'expires_at', 'consumed'];
    protected $casts = ['expires_at' => 'datetime', 'consumed' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->consumed && !$this->isExpired();
    }
}
