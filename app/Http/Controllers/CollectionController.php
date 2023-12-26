<?php

namespace App\Http\Controllers;

use App\Collection;
use App\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class CollectionController extends Controller
{
    public function syncCollections($next = null){

        $user = Auth::user();

        $collections = $user->api()->rest('GET', '/admin/smart_collections.json', [
            'limit' => 250,
        ]);
        $collections = json_decode(json_encode($collections));

        foreach ($collections->body->smart_collections as $collection) {
            $this->createCollection($collection, $user);
        }

        $collections = $user->api()->rest('GET', '/admin/custom_collections.json', [
            'limit' => 250,
        ]);
        $collections = json_decode(json_encode($collections));

        foreach ($collections->body->custom_collections as $collection) {
            $this->createCollection($collection, $user);
        }

        return Redirect::tokenRedirect('home', ['notice' => 'Collections Synced Successfully']);
    }

    public function createCollection($collection, $user)
    {

        $collect = Collection::where('shopify_id', $collection->id)->first();

        if ($collect === null) {
            $collect = new Collection();
        }

        $collect->shopify_id = $collection->id;
        $collect->title = $collection->title;
        $collect->save();


        $collection_products = $user->api()->rest('GET', '/admin/collections/'.$collect->shopify_id.'/products.json');

        $collection_products = json_decode(json_encode($collection_products));

        $collection_products_id = [];

        foreach ($collection_products->body->products as $product) {
            array_push($collection_products_id, $product->id);
        }

        $local_products = Product::whereIn('shopify_id', $collection_products_id)->pluck('id')->toArray();

        $collect->products()->sync($local_products);

//        Product::whereIn('shopify_id', $collection_products_id)->update([
//            'collection_id' => $collect->id
//        ]);

        return $collect;
    }
}
