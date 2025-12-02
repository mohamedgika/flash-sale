<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Order extends Model
{
    use HasFactory;

    protected $fillable = ['hold_id','product_id','quantity','total','status'];
}
