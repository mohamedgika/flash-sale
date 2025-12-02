<?php
namespace App\Interfaces;

use Illuminate\Http\JsonResponse;

interface ProductRepositoryInterface
{
    public function getProductById(int $id): JsonResponse;
}