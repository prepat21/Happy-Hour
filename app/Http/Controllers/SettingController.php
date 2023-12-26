<?php

namespace App\Http\Controllers;

use App\Setting;
use App\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class SettingController extends Controller
{
    public function index() {
        $user = Auth::user();

        $settings = Setting::first();
        $tags = Tag::latest()->get();

        return view('merchant.settings.show')->with([
            'user' => $user,
            'settings' => $settings,
            'tags' => $tags
        ]);
    }

    public function update(Request $request) {

        $settings = Setting::first();

        if($settings == null)
            $settings = new Setting();

        $settings->tags = implode(", ", $request->tags);
        $settings->discount_code = $request->discount_code;

        if($request->has('enable'))
            $settings->enable = 1;
        else
            $settings->enable = 0;

        $settings->save();

        return Redirect::tokenRedirect('merchant.settings', ['notice' => 'Products Synced Successfully']);

    }

}
