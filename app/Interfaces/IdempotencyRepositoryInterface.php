<?php
namespace App\Interfaces;
use Illuminate\Http\Request;

interface IdempotencyRepositoryInterface
{
     public function handle(Request $request);
}