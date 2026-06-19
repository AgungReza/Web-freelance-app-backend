<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BookingFinance extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'price',
        'discount',
        'final_price',
    ];

    protected $casts = [
        'price' => 'float',
        'discount' => 'float',
        'final_price' => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
