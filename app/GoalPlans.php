<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GoalPlans extends Model
{
    const FORM_FIELDS = [
        'employee_id' => 'Employee ID',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'date_from' => 'Date From',
        'date_to' => 'Date To',
        'store_revenue' => 'Store Revenue',
        'sell_through' => 'Sell Through',
        'percentage_profit_margin' => 'Percentage Profit Margin',
    ];

    protected $guarded = ['_token', 'goal_plan'];

    //
}
