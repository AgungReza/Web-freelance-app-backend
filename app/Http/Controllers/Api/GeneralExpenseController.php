<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\GeneralExpense;

class GeneralExpenseController extends Controller
{
    public function index(Request $request)
    {
        $expenses = GeneralExpense::where('user_id', $request->user()->id)
            ->orderBy('expense_date', 'desc')
            ->get();

        return ApiResponse::success('General expenses list', $expenses);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'expense_date' => 'required|date',
            'amount'       => 'required|numeric|min:1',
            'category'     => 'nullable|string|max:255',
            'description'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        $expense = GeneralExpense::create([
            'user_id'      => $request->user()->id,
            'expense_date' => $request->expense_date,
            'category'     => $request->category,
            'description'  => $request->description,
            'amount'       => $request->amount,
        ]);

        return ApiResponse::success('General expense created', $expense, 201);
    }

    public function update(Request $request, $id)
    {
        $expense = GeneralExpense::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'expense_date' => 'required|date',
            'amount'       => 'required|numeric|min:1',
            'category'     => 'nullable|string|max:255',
            'description'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        $expense->update([
            'expense_date' => $request->expense_date,
            'category'     => $request->category,
            'description'  => $request->description,
            'amount'       => $request->amount,
        ]);

        return ApiResponse::success('General expense updated', $expense->fresh());
    }

    public function destroy(Request $request, $id)
    {
        $expense = GeneralExpense::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $expense->delete();

        return ApiResponse::success('Expense deleted');
    }
}