<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientExpenseResource;
use App\Models\Booking;
use App\Models\ClientExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class ClientExpenseController extends Controller
{
    /**
     * GET /bookings/{booking}/expenses
     */
    public function index(Booking $booking): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [ClientExpense::class, $booking]);

        $expenses = ClientExpense::where('booking_id', $booking->id)
            ->latest('expense_date')
            ->paginate(50);

        return ClientExpenseResource::collection($expenses);
    }

    /**
     * POST /bookings/{booking}/expenses
     */
    public function store(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('create', [ClientExpense::class, $booking]);

        $data = $request->validate([
            'category'     => ['required', 'string', 'max:100'],
            'amount'       => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description'  => ['nullable', 'string', 'max:500'],
        ]);

        $expense = ClientExpense::create([
            'user_id'    => $request->user()->id,
            'booking_id' => $booking->id,
            ...$data,
        ]);

        return (new ClientExpenseResource($expense))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /bookings/{booking}/expenses/{expense}
     */
    public function destroy(Booking $booking, ClientExpense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->json(['message' => 'Expense berhasil dihapus.']);
    }
}