<?php

namespace App\Http\Controllers;

use App\GoalPlans;
use App\Http\Requests\GoalPlansRequest;
use App\LineItem;
use App\Order;
use App\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session;

class GoalPlansController extends Controller
{
    public $orders = [];
    public $user;

    public function getAPIOrders($next = NULL)
    {

        $args = [
            'status' => 'any',
            'limit' => '250',
            'source_name' => 'pos',
//            'created_at_min' => '2023-02-10T00:00:00.000Z',
//            'created_at_max' => '2023-01-29T23:59:59.000Z',
        ];
        if (!empty($next)) {
            $args['page_info'] = $next;
            unset($args['status']);
            unset($args['limit']);
            unset($args['source_name']);
            unset($args['created_at_min']);
            unset($args['created_at_max']);
        }
        $orders = $this->user->api()->rest('GET', '/admin/orders.json', $args);
        $orders = json_decode(json_encode($orders));
        $ordersArr = $orders->body->orders ?? [];
        $ordersNextLink = $orders->link->next ?? NULL;

        $this->orders = array_merge($this->orders, $ordersArr);
        if (isset($ordersNextLink)) {
            $this->getAPIOrders($ordersNextLink);
        }

        return collect($this->orders);
    }

    public function calculateMetrics($orders)
    {
        $totalRevenue = 0;
        $totalCost = 0;
        $totalItemsSold = 0;
        $totalItemsAvailable = 0;

        foreach ($orders as $order) {
            $totalRevenue += $order->total_price;
            $totalItemsSold += count($order->line_items);

            foreach ($order->line_items as $item) {
                $totalCost += $item->price * $item->quantity;
                $totalItemsAvailable += ($item->quantity + $item->fulfillable_quantity);
            }
        }

        // Calculate the metrics
        $revenue = round($totalRevenue, 2);
        $sellThrough = $totalItemsAvailable > 0 ? round(($totalItemsSold / $totalItemsAvailable) * 100, 2) : 0;
        $profitMargin = $totalRevenue > 0 ? round((($totalRevenue - $totalCost) / $totalRevenue) * 100, 2) : 0;

        return (object) [
            'revenue' => $revenue,
            'sellThrough' => $sellThrough,
            'profitMargin' => $profitMargin,
        ];
    }

    public function getMetricsGoalPlans()
    {
        $goalPlans = GoalPlans::all();
        $metricsGoalPlans = [];

        if (!empty($goalPlans)) {
            foreach ($goalPlans as $goalPlan) {

                $date_from = $goalPlan->date_from;
                $date_to = $goalPlan->date_to ?? now();

                $goalPlanOrders =
                    Order::where([
                        ['source_name', Order::SOURCE_POS],
                        ['shopify_created_at', '>', $date_from],
                        ['shopify_created_at', '<', $date_to],
                    ])
                    ->get();

                $metrics = $this->calculateMetrics($goalPlanOrders);
                $count = $goalPlanOrders->count();
                $metricsGoalPlans[] = (object) compact('goalPlan', 'metrics', 'count');
            }
        }

        return $metricsGoalPlans;
    }


    public function syncOrders($collection)
    {
        $shopifyIDsNew = $collection->pluck('id')->toArray();
        $shopifyIDsOld =
            Order::whereIn('shopify_id', $shopifyIDsNew)
            ->where('source_name', Order::SOURCE_POS)
            ->get()
            ->pluck('shopify_id')
            ->toArray();

        $diff = array_diff($shopifyIDsNew, $shopifyIDsOld);

        $newOrders = $collection->filter(function ($order, int $key) use ($diff) {
            return in_array($order->id, $diff);
        });

        if (!empty($newOrders)) {
            foreach ($newOrders as $order) {
                $o = Order::where('shopify_id', $order->id)->first();

                if ($o == NULL) {
                    $o = new Order();
                }

                $o->shopify_id = $order->id;
                $o->name = $order->name;
                $o->shopify_created_at = date_create($order->created_at)->format('Y-m-d h:i:s');

                // NEW FIELDS FOR GOAL PLANS.
                $o->source_name = Order::SOURCE_POS;
                $o->total_price = $order->total_price;

                $o->save();

                foreach ($order->line_items as $item) {

                    $new_line = LineItem::where([
                        'order_id' => $o->id,
                        'shopify_variant_id' => $item->variant_id,
                        'shopify_product_id' => $item->product_id
                    ])->first();

                    if($new_line == NULL) {
                        $new_line = new LineItem();
                    }

                    $new_line->order_id = $o->id;
                    $new_line->shopify_id = $item->id;
                    $new_line->shopify_product_id = $item->product_id;
                    $new_line->shopify_variant_id = $item->variant_id;
                    $new_line->title = $item->title;
                    $new_line->vendor = $item->vendor;
                    $new_line->name = $item->name;

                    // NEW FIELDS FOR GOAL PLANS.
                    $new_line->price = $item->price;
                    $new_line->quantity = $item->quantity;
                    $new_line->fulfillable_quantity = $item->fulfillable_quantity;

                    $new_line->save();
                }
            }
        }

    }

    public function index()
    {
        $this->user = Auth::user();

        $apiOrders = $this->getAPIOrders();
        $apiOrders = $apiOrders->sortBy('order_number', $options = SORT_REGULAR, $descending = true);

        // SYNC orders from API with DB.
        $this->syncOrders($apiOrders);

        // get metrics by orders from DB.
        $metricsGoalPlans = $this->getMetricsGoalPlans();

        $last = Order::where('source_name', Order::SOURCE_POS)->orderBy('shopify_created_at')->get()->first();
        return view('merchant.goalplans.index', compact('metricsGoalPlans', 'last'));
    }

    public function dashboard()
    {
        return view('merchant.goalplans.dashboard');
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'first_name' => 'string',
            'last_name' => 'string',
            'date_from' => 'required|date',
            'date_to' => 'required|date',
            'store_revenue' => 'required|integer',
            'sell_through' => 'required|integer',
            'percentage_profit_margin' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $errMessage = 'Whoops! There were some problems with your input. ' . implode(' ', $errors->all());
            return Redirect::tokenRedirect('goal-plans.edit', ['notice' => $errMessage, 'goal_plan' => $request->id]);
        }

        $goalPlan = GoalPlans::find($request->id);
        $goalPlan->update($request->all());

        return Redirect::tokenRedirect('goal-plans.index', ['notice' => 'Goal Plan Update Successfully']);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'first_name' => 'string',
            'last_name' => 'string',
            'date_from' => 'required|date',
            'date_to' => 'required|date',
            'store_revenue' => 'required|integer',
            'sell_through' => 'required|integer',
            'percentage_profit_margin' => 'required|integer',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $errMessage = 'Whoops! There were some problems with your input. ' . implode(' ', $errors->all());
            return Redirect::tokenRedirect('goal-plans.create', ['notice' => $errMessage]);
        }

        GoalPlans::create($request->all());

        return Redirect::tokenRedirect('goal-plans.index', ['notice' => 'Goal Plan Create Successfully']);
    }

    public function create()
    {
        $formFields = GoalPlans::FORM_FIELDS;
        return view('merchant.goalplans.create', compact('formFields'));
    }

    public function delete($goalPlanID)
    {
        $goalPlan = GoalPlans::find($goalPlanID);
        $goalPlan->delete();

        return Redirect::tokenRedirect('goal-plans.index', ['notice' => 'Goal Plan Delete Successfully']);
    }

    public function edit($goalPlanID)
    {
        $goalPlan = GoalPlans::find($goalPlanID);
        $formFields = GoalPlans::FORM_FIELDS;
        return view('merchant.goalplans.edit', compact('goalPlan', 'formFields'));
    }

}
