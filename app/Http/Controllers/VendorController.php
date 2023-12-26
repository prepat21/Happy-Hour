<?php

namespace App\Http\Controllers;

use App\Collection;
use App\Jobs\UpdatePrice;
use App\ProductType;
use App\Rule;
use App\Setting;
use App\Tag;
use App\User;
use App\Variant;
use App\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session;

class VendorController extends Controller
{
    public $api;

    public function __construct(){
        $user = User::first();
        $options = new Options();
        $options->setVersion('2020-01');
        $this->api = new BasicShopifyAPI($options);
        $this->api->setSession(new Session($user->name, $user->password));
    }

    public function index() {

        $user = Auth::user();
        $vendors = Vendor::orderBy('name')->get();

        return view('merchant.vendors.index')->with([
            'user' => $user,
            'vendors' => $vendors
        ]);
    }

    public function getRules($id) {
        $vendor = Vendor::find($id);
        $user = Auth::user();
        $tags = Tag::where('name', '!=', '')->get();
        $collections = Collection::all();
        $types = ProductType::all();

        return view('merchant.vendors.rules')->with([
            'vendor' => $vendor,
            'user' => $user,
            'tags' => $tags,
            'collections' => $collections,
            'types' => $types
        ]);
    }

    public function createRule(Request $request, $id) {
        $vendor = Vendor::find($id);

        $rule = new Rule();

        $rule->type = $request->type;
        $rule->operator = $request->operator;
        $rule->vendor_id = $vendor->id;
        $rule->enable = 1;
        $rule->reduce_by = $request->reduce_by;


        if($request->type == 'collection')
            $rule->value = $request->collection;
        elseif($request->type == 'product_tag')
            $rule->value = $request->product_tag;
        elseif($request->type == 'product_type')
            $rule->value = $request->product_type;
        else
            $rule->value = $request->value;

        $rule->save();

        return Redirect::tokenRedirect('merchant.vendors.rules', ['notice' => 'Rule Updated Successfully', 'id' => $vendor->id]);

    }

    public function updateRule(Request $request, $id, $rule_id) {

        $vendor = Vendor::find($id);
        $rule = Rule::find($rule_id);

        $rule->type = $request->type;
        $rule->operator = $request->operator;
        $rule->vendor_id = $vendor->id;
        $rule->reduce_by = $request->reduce_by;

        if($request->has('enable'))
            $rule->enable = 1;
        else
            $rule->enable = 0;



        if($request->type == 'collection')
            $rule->value = $request->collection;
        elseif($request->type == 'product_tag')
            $rule->value = $request->product_tag;
        elseif($request->type == 'product_type')
            $rule->value = $request->product_type;
        else
            $rule->value = $request->value;

        $rule->save();

        return Redirect::tokenRedirect('merchant.vendors.rules', ['notice' => 'Rule Updated Successfully', 'id' => $vendor->id]);

    }

    public function deleteRule($id, $rule_id) {

        $vendor = Vendor::with('products.variants')->where('id',$id)->first();

        $variant_list = [];
        $final_rule = null;
        $highest_value = 0;
        $ruleActive = Rule::find($rule_id);

        foreach ($vendor->products as $product) {

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

            if (
                $final_rule
            ) {
                $this->revertProductPrice($final_rule->reduce_by, $product, $final_rule, $ruleActive);
                $final_rule = null;
                $highest_value = 0;
            }
        }

        // Delete the rule
        $rule->delete();

        return Redirect::tokenRedirect('merchant.vendors.rules', ['notice' => 'Rule Deleted Successfully', 'id' => $id]);

    }

    public function actionRules($action)
    {

        $vendors = Vendor::all();

        $mainTotal = 0;
        $mainChangedVariants = [];

        foreach ($vendors as $vendor) {
            $final_rule = null;
            $highest_value = 0;
            foreach ($vendor->products as $product) {
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

                if (
                $final_rule
                ) {
                    $result = $this->updateProductPrice($final_rule->reduce_by, $product, $final_rule, $final_rule, $action);
                    $mainTotal = $mainTotal + $result['total_price_changes'];
                    $mainChangedVariants = array_merge($mainChangedVariants,  $result['changed_variants']);

                    $final_rule = null;
                    $highest_value = 0;
                }
            }
        }

        switch ($action) {
            case 'pause':
                $notice = 'Rules Pause Successfully';
                $settings = Setting::first();
                $settings->rules_enable = 0;
                $settings->save();
                break;

            case 'resume':
                $notice = 'Rules Resume Successfully';
                $settings = Setting::first();
                $settings->rules_enable = 1;
                $settings->save();
                break;
        }

        $this->sendMail($mainTotal, $mainChangedVariants, $action);


        return Redirect::tokenRedirect('home', ['notice' => $notice]);
    }

    public function sendMail($total_price_changes, $changed_variants, $action) {
        $apikey = '2F649F52E1DF3938B894A2E163347E72F7C8474051FA288E679AEEABD4746F7ADAD4656ED01A03B99509D4FC60689281';
        $to = 'info@thebettergeneration.com';
//        $to = 'andreamelkov61@gmail.com';
        $from = 'noreply@thebettergeneration.com';
        $fromName = 'The Better Generation Discount System';
        $subject = 'Total Price Changes: ' . $total_price_changes;
        $bodyText = 'The following variants have had their prices changed:';
        foreach ($changed_variants as $variant) {
            $bodyText .= "\n\nVariant Name: " .  $variant['product_title'] . " - " . $variant['name'] . "\nOld Price: " . $variant['old_price'] . "\nNew Price: " . $variant['new_price'];
        }

//        Log::debug('EMAIL - ' . $subject . $bodyText);
//        Log::debug('changed_variants - ' . print_r($changed_variants, true));

        $data = array(
            'apikey' => $apikey,
            'subject' => $subject,
            'from' => $from,
            'fromName' => $fromName,
            'to' => $to,
            'bodyHtml' => nl2br($bodyText),
            'bodyText' => $bodyText,
            'isTransactional' => false
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.elasticemail.com/v2/email/send');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        if (strpos($response, 'success') !== false) {
            echo 'Email sent successfully';
        } else {
            echo 'Email sending failed';
        }
    }

    public function deleteRules()
    {
        $vendors = Vendor::all();
        foreach ($vendors as $vendor) {
            $final_rule = null;
            $highest_value = 0;
            foreach ($vendor->products as $product) {
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

                if (
                $final_rule
                ) {
                    $this->revertProductPrice($final_rule->reduce_by, $product, $final_rule, $final_rule);
                    // Delete the rule
//                    $modelRule = Rule::find($rule->id);
//                    $modelRule->delete();

                    $final_rule = null;
                    $highest_value = 0;
                }
            }
        }

        Rule::query()->delete();

        return Redirect::tokenRedirect('home', ['notice' => 'Rules Deleted Successfully']);

    }
    

    public function verifyPriceUpdation($id) {

        $vendor = Vendor::with('products.variants')->where('id',$id)->first();

        $variant_list = [];
        $final_rule = null;
        $highest_value = 0;

        foreach ($vendor->products as $product) {

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

            if ($final_rule) {
                array_push($variant_list, $this->updateVariantList($final_rule->reduce_by, $product, $final_rule));
                $final_rule = null;
                $highest_value = 0;
            }
        }

        $html =  view('merchant.vendors.prices')->with(['products_list' => $variant_list])->render();

        return response()->json(['html' => $html, 'matches' => count($variant_list)]);
    }

    public function updateProductPrice($reduce_by, $product, $rule, $ruleActive, $action) {

        $total_price_changes = 0;
        $changed_variants = [];

        foreach ($product->variants as $variant) {

            if ($reduce_by == $ruleActive->reduce_by) {

                $variantModel = Variant::find($variant->id);

                switch ($action) {
                    case 'resume':
                        $variantModel = Variant::find($variant->id);
                        $variantModel->old_price = $variantModel->ca_price;

                        $old_price = $variant->ca_price;
                        $new_price = $old_price - ($old_price * $reduce_by/100);
                        $variantModel->price = $new_price;

                        $payload = [
                            "variant" => [
                                'price' => $variantModel->price,
                                'compare_at_price' => $variantModel->ca_price
                            ]
                        ];

                        $total_price_changes++;

                        $changed_variants[] = [
                            'name' => $variantModel->title,
                            'old_price' => $variantModel->ca_price,
                            'new_price' => $variantModel->price,
                            'product_title' => $product->title,
                        ];

                        $product_shopify_id = $variantModel->shopify_product_id;
                        $variant_shopify_id = $variantModel->shopify_id;
                        $this->api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
                        $variantModel->save();
                        break;

                    case 'pause':
                        $variantModel = Variant::find($variant->id);
                        $variantModel->old_price = $variantModel->ca_price;

                        $payload = [
                            "variant" => [
                                'price' => $variantModel->ca_price,
                                'compare_at_price' => $variantModel->ca_price
                            ]
                        ];

                        $total_price_changes++;

                        $changed_variants[] = [
                            'name' => $variantModel->title,
                            'old_price' => $variantModel->ca_price,
                            'new_price' => $variantModel->ca_price,
                            'product_title' => $product->title,
                        ];

                        $product_shopify_id = $variantModel->shopify_product_id;
                        $variant_shopify_id = $variantModel->shopify_id;
                        $this->api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
                        $variantModel->save();



                        break;
                }






                $product_shopify_id = $variantModel->shopify_product_id;
                $variant_shopify_id = $variantModel->shopify_id;
                $this->api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
                $variantModel->save();
            }


        }

        return [
            'total_price_changes' => $total_price_changes,
            'changed_variants' => $changed_variants,
        ];
    }

    public function revertProductPrice($reduce_by, $product, $rule, $ruleActive) {
        foreach ($product->variants as $variant) {

            if ($reduce_by == $ruleActive->reduce_by) {
                $variantModel = Variant::find($variant->id);
                $variantModel->old_price = $variantModel->ca_price;

                $payload = [
                    "variant" => [
                        'price' => $variantModel->ca_price,
                        'compare_at_price' => $variantModel->ca_price
                    ]
                ];

                $product_shopify_id = $variantModel->shopify_product_id;
                $variant_shopify_id = $variantModel->shopify_id;
                $this->api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
                $variantModel->save();
            }


        }
    }

    public function updateVariantList($reduce_by, $product, $rule) {


        $temp_array = [];
        foreach ($product->variants as $variant) {
            

                $old_price = $variant->ca_price;

                $new_price = $old_price - ($old_price * $reduce_by/100);
        
                array_push($temp_array, [
                    'product_title' => $product->title,
                    'variant_title' => $variant->title,
                    'product_id' => $product->id,
                    'product_shopify_id' => $product->shopify_id,
                    'variant_id' => $variant->id,
                    'variant_shopify_id' => $variant->shopify_id,
                    'new_price' => $new_price,
                    'old_price' => $old_price,
                    'rule_applied' => $rule->type,
                    'reduced_by' => $reduce_by
                ]);
            
        }
        
        return $temp_array;
        
    }


    public function updatePrices(Request $request, $id) {

        if(!isset($request->variant_shopify_id))
            return Redirect::tokenRedirect('merchant.vendors.rules', ['notice' => 'No Matches Found!', 'id' => $id]);


        UpdatePrice::dispatch($request->all())->onConnection('database');

        return Redirect::tokenRedirect('merchant.vendors.rules', ['notice' => 'Prices will be updated shortly!', 'id' => $id]);
    }


    public function updatePosition(Request $request) {

        $vendor = Vendor::find($request->vendor);

        $rules = Rule::where('vendor_id', $vendor->id)->get();

        foreach ($rules as $rule) {
            foreach ($request->order as $order) {
                if ($order['id'] == $rule->id) {
                    $rule->update(['position' => $order['position']]);
                }
            }
        }

        return response()->json(['status', 'success']);
    }


}
