<?php

namespace App\Http\Controllers;

use App\LineItem;
use App\Order;
use App\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class OrderController extends Controller
{
    public function syncOrders(){

        $user = Auth::user();
        $orders = $user->api()->rest('GET', '/admin/orders.json', ['status' => 'any', 'limit' => '250']);
        $orders = json_decode(json_encode($orders));

        if(isset($orders->body)) {
            foreach ($orders->body->orders as $order)
                $this->createShopifyOrder($order);
        }


        return Redirect::tokenRedirect('home', ['notice' => 'Orders Synced Successfully']);
    }

    public function createShopifyOrder($order){
        $o = Order::where('shopify_id', $order->id)->first();

        if($o == null)
            $o = new Order();

        $o->shopify_id = $order->id;
        $o->name = $order->name;
        $o->shopify_created_at = date_create($order->created_at)->format('Y-m-d h:i:s');
        $o->save();


        foreach ($order->line_items as $item){

            $new_line = LineItem::where([
                'order_id' => $o->id,
                'shopify_variant_id' => $item->variant_id,
                'shopify_product_id' => $item->product_id
            ])->first();

            if($new_line === null)
                $new_line = new LineItem();

            $new_line->order_id = $o->id;
            $new_line->shopify_id = $item->id;
            $new_line->shopify_product_id = $item->product_id;
            $new_line->shopify_variant_id = $item->variant_id;
            $new_line->title = $item->title;
            $new_line->vendor = $item->vendor;
            $new_line->name = $item->name;
            $new_line->save();


            $product = Product::where('shopify_id', $item->product_id)->first();

            if($product) {
                $latest_order = Order::whereHas('line_items', function ($query) use ($item) {
                    $query->where('shopify_product_id', $item->product_id);
                })->orderBy('shopify_created_at')->first();


                $product->days_since_last_sale = now()->diffInDays($latest_order->shopify_created_at);
                $product->save();
            }
        }


    }

}
