<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoalPlansRequest extends FormRequest
{
    
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'employee_id' => 'required|integer',
            'first_name' => 'string',
            'last_name' => 'string',
            'date_from' => 'required',
            'date_to' => 'required',
            'store_revenue' => 'required|integer',
            'sell_through' => 'required|integer',
            'percentage_profit_margin' => 'required|integer',
        ];
    }
}
