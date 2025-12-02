<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;


Route::get('/', function () {
    return view('welcome');
});