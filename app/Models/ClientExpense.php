<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientExpense extends Model
{
    use HasFactory;

    protected $table = 'client_expenses';

    protected $fillable = [
        'user_id',
        'booking_id',
        'category',
        'description',
        'amount',
        'expense_date',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(BookingModel::class, 'booking_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
