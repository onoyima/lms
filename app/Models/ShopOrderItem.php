<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['shop_order_id', 'test_package_id', 'title', 'price'];
}
