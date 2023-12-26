<?php

namespace App\Http\Controllers;

use App\Product;
use App\Setting;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function verifyDiscount(Request $request) {

        $setting = Setting::first();
        $setting_tags = explode(', ', $setting->tags);

        if($setting && $setting->enable == 0)
            return response()->json(['status' => 'failure']);

        $line_items = $request->items;
        $product_ids = [];

        foreach ($line_items as $item)
            array_push($product_ids, $item['product_id']);

        if(count($product_ids) == 0)
            return response()->json(['status' => 'failure']);

        $products = Product::whereIn('shopify_id', $product_ids)->get();

        if(count($products) == 0)
            return response()->json(['status' => 'failure']);

        foreach ($products as $product) {
            $product_tags = explode(', ', $product->tags);


            foreach ($setting_tags as $index => $setting_tag) {
                if(in_array($setting_tag, $product_tags))
                    return response()->json(['status' => 'success', 'discount_code' => $setting->discount_code]);
            }
        }

        return response()->json(['status' => 'failure']);
    }
}
