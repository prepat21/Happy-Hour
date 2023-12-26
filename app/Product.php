<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public function collection() {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function collections() {
        return $this->belongsToMany(Collection::class);
    }

    public function variants() {
        return $this->hasMany(Variant::class, 'product_id');
    }

    public function has_type() {
        return $this->belongsTo(ProductType::class, 'type_id');
    }
}
