<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'user_id',
        'customer_id',
        'job_type',
        'job_package',
        'day_book',
        'start_datetime',
        'end_datetime',
        'location',
        'work_status',
    ];

    protected $casts = [
        'day_book' => 'date',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function addOns()
    {
        return $this->hasMany(AddOn::class);
    }

    public function finance()
    {
        return $this->hasOne(BookingFinance::class);
    }

    public function payments()
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function histories()
    {
        return $this->hasMany(BookingHistory::class);
    }
}