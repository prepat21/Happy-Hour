<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $dates = ['shopify_created_at'];

    const SOURCE_POS = 1;

    public function line_items() {
        return $this->hasMany(LineItem::class, 'order_id');
    }
}
