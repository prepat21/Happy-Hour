<?php

namespace App\Jobs;

use App\User;
use App\Variant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session;

class UpdatePrice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $req;
    public $timeout = 0;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($req)
    {
        $this->req = $req;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $request = json_decode(json_encode($this->req));
    
        $user = User::first();
        $options = new Options();
        $options->setVersion('2020-01');
        $api = new BasicShopifyAPI($options);
        $api->setSession(new Session($user->name, $user->password));

        $total_price_changes = 0;
        $changed_variants = [];
        foreach ($request->variant_shopify_id as $index => $variant_shopify_id) {
            $product = $api->rest('GET', '/admin/api/2020-01/products/'.$request->product_shopify_id[$index].'.json')['body']['product'];
            $tags = $product['tags'];

            $total_price_changes++;

            $changed_variants[] = $this->changePrice(
                $variant_shopify_id,
                $request->product_shopify_id[$index],
                $request->new_price[$index],
                $request->old_price[$index],
                $api,
                $request->variant_id[$index],
                $tags,
                $product->title,
            );
        }

        $this->sendMail($total_price_changes, $changed_variants);

    
    }

    public function changePrice($variant_shopify_id, $product_shopify_id, $new_price, $old_price, $api, $variant_id, $tags, $productTitle) {

        $local_variant = Variant::find($variant_id);

        $payload = [ 
            "variant" => [
                'price' => $new_price,
                'compare_at_price' => $old_price
            ] 
        ];
    
        $response = $api->rest('PUT', '/admin/products/'.$product_shopify_id.'/variants/'.$variant_shopify_id.'.json',$payload);
        $response = json_decode(json_encode($response));
    
        $total_price_changes = 0;
        $changed_variants = [];
    
        if(!$response->errors) {
            $local_variant->price = $new_price;
            $local_variant->old_price = $old_price;
            $local_variant->save();
            $total_price_changes++;
            $changed_variants[] = [
                'name' => $local_variant->title,
                'old_price' => $old_price,
                'new_price' => $new_price,
                'product_title' => $productTitle,
            ];
        }

        return [
            'name' => $local_variant->title,
            'old_price' => $old_price,
            'new_price' => $new_price,
            'product_title' => $productTitle,
        ];
    
    }
    
    public function sendMail($total_price_changes, $changed_variants) {
        $apikey = '2F649F52E1DF3938B894A2E163347E72F7C8474051FA288E679AEEABD4746F7ADAD4656ED01A03B99509D4FC60689281';
        $to = 'nikolaos.vassos@gmail.com';
//        $to = 'andreamelkov61@gmail.com';
        $from = 'noreply@thebettergeneration.com';
        $fromName = 'The Better Generation Discount System';
        $subject = 'Total Price Changes: ' . $total_price_changes;
        $bodyText = 'The following variants have had their prices changed:';
        foreach ($changed_variants as $variant) {
            $bodyText .= "\n\nVariant Name: " . $variant['product_title'] . " - " . $variant['name'] . "\nOld Price: " . $variant['old_price'] . "\nNew Price: " . $variant['new_price'];
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
    
    

}
