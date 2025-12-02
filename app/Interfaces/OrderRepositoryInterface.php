<?php
namespace App\Interfaces;

use Illuminate\Http\Request;

interface OrderRepositoryInterface
{
    public function store(Request $request);
}