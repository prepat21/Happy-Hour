<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    public function products() {
        return $this->hasMany(Product::class, 'vendor_id');
    }

    public function rules() {
        return $this->hasMany(Rule::class, 'vendor_id');
    }
}
