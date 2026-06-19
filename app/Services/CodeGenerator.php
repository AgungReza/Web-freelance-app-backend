<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Support\Str;

class CodeGenerator
{
    /*
    |--------------------------------------------------------------------------
    | BOOKING CODE
    |--------------------------------------------------------------------------
    */

    public static function bookingCode()
    {
        do {
            $code = 'BK-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER CODE
    |--------------------------------------------------------------------------
    */

    public static function customerCode()
    {
        do {
            $code = 'CUST-' . date('Y') . '-' . strtoupper(Str::random(5));
        } while (Customer::where('customer_code', $code)->exists());

        return $code;
    }
}