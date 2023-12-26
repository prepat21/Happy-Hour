<?php

namespace App\Http\Controllers;

use App\Product;
use App\ProductType;
use App\Tag;
use App\User;
use App\Variant;
use App\Vendor;
use Cassandra\Varint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session;


class ProductController extends Controller
{
    private $_changed_variants = [];
    private $_total_price_changes = 0;

    public $api;

    public function syncProducts($next = null){

        $user = Auth::user();

        if(!$user && app()->runningInConsole()){
            $user = User::first();
            $options = new Options();
            $options->setVersion('2020-01');
            $this->api = new BasicShopifyAPI($options);
            $this->api->setSession(new Session($user->name, $user->password));
        }

        $products = $user->api()->rest('GET', '/admin/products.json', [
            'limit' => 250,
            'page_info' => $next
        ]);

        $products = json_decode(json_encode($products));

        if(isset($products->body->products))
        {
            foreach ($products->body->products as $product) {
                $this->createProduct($product, $user);
            }
        }

        if (isset($products->link->next)) {
            return $this->syncProducts($products->link->next);
        }

        $vendorController = new VendorController();
        $vendorController->sendMail($this->_total_price_changes, $this->_changed_variants, 'apply');

        if(!app()->runningInConsole()){
           return Redirect::tokenRedirect('home', ['notice' => 'Products Synced Successfully']);
        }
    }

    public function createProduct($product)
    {
        $prod = Product::where('shopify_id', $product->id)->first();

        if ($prod === null) {
            $prod = new Product();
        }

        $prod->shopify_id = $product->id;
        $prod->title = $product->title;
        $prod->sales_channel = $product->published_scope;
        $prod->tags = $product->tags;
        $prod->vendor = $product->vendor;





        $tags = explode(',', $product->tags);

        foreach ($tags as $tag_name) {
            $tag = Tag::where('name', $tag_name)->first();

            if($tag == null)
                $tag = new Tag();

            $tag->name = $tag_name;
            $tag->save();
        }


        $vendor = Vendor::where('name', $product->vendor)->first();

        if($vendor == null)
            $vendor = new Vendor();

        $vendor->name = $product->vendor;
        $vendor->save();

        $type = ProductType::where('name', $product->product_type)->first();

        if($type == null)
            $type = new ProductType();

        $type->name = $product->product_type;
        $type->save();

        $prod->vendor_id = $vendor->id;
        $prod->type_id = $type->id;

        $prod->shopify_created_at = date_create($product->created_at)->format('Y-m-d h:i:s');

        if($product->published_at != null && $product->published_at != 'null')
            $prod->published_at = date_create($product->published_at)->format('Y-m-d h:i:s');

        $prod->save();

        $inventory_sum = 0;

        if (count($product->variants) >= 1) {
            foreach ($product->variants as $variant) {

                if (Variant::where('shopify_id', $variant->id)->exists()) {
                    $variant_add = Variant::where('shopify_id', $variant->id)->first();
                } else {
                    $variant_add = new Variant();
                }

                $variant_add->shopify_id = $variant->id;
                $variant_add->title = $variant->title;
                $variant_add->price = $variant->ca_price;
                $variant_add->product_id = $prod->id;
                $variant_add->shopify_product_id = $product->id;
                $variant_add->save();

                $inventory_sum += $variant->inventory_quantity;
            }
        }

        $prod->inventory_quantity = $inventory_sum;
        $prod->save();

        $final_rule = null;
        $highest_value = 0;

        foreach ($vendor->rules()->where('enable', 1)->orderBy('position')->get() as $rule) {
            if($rule->type == 'days_active') {
                $days = $rule->value;
                $operator = $rule->operator;

                if( ($operator == 'greater_then' && $product->published_at && now()->diffInDays($product->published_at) > $days) ||
                    ($operator == 'equals_to' && $product->published_at && now()->diffInDays($product->published_at) == $days) ||
                    ($operator == 'less_then' && $product->published_at && now()->diffInDays($product->published_at) < $days) ) {
                    if ($rule->value > $highest_value) {
                        $highest_value = $rule->value;
                        $final_rule = $rule;
                    }
                }
            }
        }

        if ( $final_rule ) {

            foreach ($product->variants as $variant) {

                $variantModel = Variant::where('shopify_id', $variant->id)->first();
                $variantModel->old_price = $variantModel->ca_price;

                $old_price = $variantModel->ca_price;
                $new_price = $old_price - ($old_price * $final_rule->reduce_by/100);
                $variantModel->price = $new_price;

                $payload = [
                    "variant" => [
                        'price' => $variantModel->price,
                        'compare_at_price' => $variantModel->ca_price
                    ]
                ];

                $this->_changed_variants[] = [
                    'name' => $variantModel->title,
                    'old_price' => $variantModel->ca_price,
                    'new_price' => $variantModel->price,
                    'product_title' => $product->title,
                ];

                $this->_total_price_changes ++;

                $product_shopify_id = $variantModel->shopify_product_id;
                $variant_shopify_id = $variantModel->shopify_id;
                $this->api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
                $variantModel->save();
            }
        }

        return $prod;
    }

}
