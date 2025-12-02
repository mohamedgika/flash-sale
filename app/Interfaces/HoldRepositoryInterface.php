<?php
namespace App\Interfaces;
use Illuminate\Http\Request;
interface HoldRepositoryInterface
{
    public function store(Request $request);
}