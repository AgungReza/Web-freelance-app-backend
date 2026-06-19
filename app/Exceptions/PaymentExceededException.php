<?php

namespace App\Exceptions;

/**
 * Dilempar ketika total pembayaran melebihi final_price booking.
 *
 * Dengan custom exception ini, BookingController bisa menangkap
 * error ini secara type-safe tanpa membandingkan string pesan.
 */
class PaymentExceededException extends \RuntimeException
{
    public function __construct(string $message = 'Pembayaran melebihi tagihan')
    {
        parent::__construct($message);
    }
}