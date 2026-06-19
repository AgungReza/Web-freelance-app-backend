<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'expired_at',
        'attempts',
        'locked_until',
        'verified',
        'verified_at',   
        'reset_token',    
        'resend_available_at',
    ];

    protected function casts(): array
    {
        return [
            'expired_at'           => 'datetime',
            'locked_until'         => 'datetime',
            'verified_at'          => 'datetime',          
            'resend_available_at'  => 'datetime',          
            'verified'             => 'boolean',
        ];
    }
}