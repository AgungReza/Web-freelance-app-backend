<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaxProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'has_npwp',
        'npwp',
        'ptkp_status',
    ];

    protected $casts = [
        'has_npwp' => 'boolean',
    ];

    // Nilai PTKP per status (UU HPP No. 7/2021)
    const PTKP_VALUES = [
        'TK/0' => 54_000_000,
        'TK/1' => 58_500_000,
        'TK/2' => 63_000_000,
        'TK/3' => 67_500_000,
        'K/0'  => 58_500_000,
        'K/1'  => 63_000_000,
        'K/2'  => 67_500_000,
        'K/3'  => 72_000_000,
    ];

    const PTKP_DESCRIPTIONS = [
        'TK/0' => 'Tidak kawin, tanpa tanggungan',
        'TK/1' => 'Tidak kawin, 1 tanggungan',
        'TK/2' => 'Tidak kawin, 2 tanggungan',
        'TK/3' => 'Tidak kawin, 3 tanggungan',
        'K/0'  => 'Kawin, tanpa tanggungan',
        'K/1'  => 'Kawin, 1 tanggungan',
        'K/2'  => 'Kawin, 2 tanggungan',
        'K/3'  => 'Kawin, 3 tanggungan',
    ];

    public function getPtkpValueAttribute(): int
    {
        return self::PTKP_VALUES[$this->ptkp_status] ?? 54_000_000;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}