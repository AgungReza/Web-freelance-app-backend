<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'booking_id'   => $this->booking_id,
            'category'     => $this->category,
            'amount'       => (float) $this->amount,
            'expense_date' => $this->expense_date,           // Y-m-d string
            'description'  => $this->description,
            'created_at'   => $this->created_at->toISOString(),
        ];
    }
}