<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaxRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'bruto',
        'dpp',
        'ptkp_status',
        'ptkp_value',
        'pkp',
        'tax_before_correction',
        'npwp_correction',
        'tax_final',
        'credit_tax',
        'tax_payable',
        'tax_layers',
        'effective_rate',
        'calculated_at',
    ];

    protected $casts = [
        'bruto'                 => 'decimal:2',
        'dpp'                   => 'decimal:2',
        'ptkp_value'            => 'decimal:2',
        'pkp'                   => 'decimal:2',
        'tax_before_correction' => 'decimal:2',
        'npwp_correction'       => 'decimal:2',
        'tax_final'             => 'decimal:2',
        'credit_tax'            => 'decimal:2',
        'tax_payable'           => 'decimal:2',
        'effective_rate'        => 'decimal:2',
        'tax_layers'            => 'array',
        'calculated_at'         => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}