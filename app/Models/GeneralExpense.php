<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralExpense extends Model
{
    protected $table = 'general_expenses';

    protected $fillable = [
        'user_id',
        'expense_date',
        'category',
        'description',
        'amount'
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
