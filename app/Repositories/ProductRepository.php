<?php

namespace App\Repositories;

use App\Interfaces\ProductRepositoryInterface;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
class ProductRepository implements ProductRepositoryInterface
{
    public function getProductById(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        return response()->json(['data' => $product]);
    }
}