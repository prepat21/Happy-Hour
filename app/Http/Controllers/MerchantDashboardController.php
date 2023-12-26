<?php

namespace App\Http\Controllers;

use App\Product;
use App\Rule;
use App\Setting;
use App\User;
use App\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session;

class MerchantDashboardController extends Controller
{

    public function dashboard()
    {
        $user = Auth::user();

        $products = Product::count();
        $vendors = Vendor::count();
        $rules = Rule::count();
        $settings = Setting::first();
        $rules_enable = $settings->rules_enable;

        return view('merchant.dashboard')->with([
            'user' => $user,
            'products' => $products,
            'vendors' => $vendors,
            'rules' => $rules,
            'rules_enable' => $rules_enable,
        ]);
    }

    public function index(Request $request) {
        $user = User::first();
//        $options = new Options();
//        $options->setVersion('2020-01');
//        $api = new BasicShopifyAPI($options);
//        $api->setSession(new Session($user->name, $user->password));
//        $result = $api->rest('GET', '/admin/api/2020-01/users/current.json');

//        $result = $user->api()->rest('GET', '/admin/api/2022-04/users/account.json');
//        dump($result);

        $user = User::first();
        $options = new Options();
        $options->setVersion('2022-04');
        $api = new BasicShopifyAPI($options);
        $api->setSession(new Session($user->name, $user->password));
        $result = $api->getSession();
        dump($result);

        return view('merchant.goalplans.dashboard');
    }
}
