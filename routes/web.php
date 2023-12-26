<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['middleware' => ['verify.shopify'] ], function () {
    Route::get('/', "MerchantDashboardController@index")->name('home');

    Route::group(['prefix' => 'merchant'], function () {
        Route::get('/dashboard', "MerchantDashboardController@dashboard")->name('merchant.dashboard');
        Route::get('/vendors', "VendorController@index")->name('merchant.vendors');
        Route::get('/vendors/{id}/rules', "VendorController@getRules")->name('merchant.vendors.rules');
        Route::post('/vendors/{id}/rules/create', "VendorController@createRule")->name('merchant.vendors.create.rule');
        Route::post('/vendors/{id}/rules/{rule_id}/update', "VendorController@updateRule")->name('merchant.vendors.update.rule');
        Route::get('/vendors/{id}/rules/{rule_id}/delete', "VendorController@deleteRule")->name('merchant.vendors.delete.rule');
        Route::get('/settings', "SettingController@index")->name('merchant.settings');
        Route::get('/sync-products','ProductController@syncProducts')->name('merchant.products.sync');
        Route::get('/sync-orders','OrderController@syncOrders')->name('merchant.orders.sync');
        Route::get('/sync-collections','CollectionController@syncCollections')->name('merchant.collections.sync');
        Route::get('/goal-plans/dashboard', "GoalPlansController@dashboard")->name('goal-plans.dashboard');
        Route::resource('goal-plans', 'GoalPlansController');
        Route::get('goal-plans/{goal_plan}/delete', 'GoalPlansController@delete')->name('goal-plans.delete');
    });
});

Route::post('/rule-update-position', 'VendorController@updatePosition');
Route::post('/settings/update', "SettingController@update")->name('merchant.settings.update');
Route::post('/verify-discount', 'CheckoutController@verifyDiscount')->name('verify.discount');
Route::get('/vendors/{id}/rules/apply', "VendorController@verifyPriceUpdation")->name('merchant.vendors.apply.rules');
Route::get('/vendors/rules/delete', "VendorController@deleteRules")->name('merchant.vendors.delete.rules');
Route::get('/vendors/rules/{action}', "VendorController@actionRules")->name('merchant.vendors.action.rules');
Route::post('/vendors/{id}/update/prices', "VendorController@updatePrices")->name('merchant.vendors.update.prices');
