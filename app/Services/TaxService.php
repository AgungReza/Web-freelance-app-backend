<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TaxProfile;
use App\Models\TaxRecord;
use Illuminate\Support\Facades\DB;

class TaxService
{
    // Lapisan tarif Pasal 17 (UU HPP No. 7/2021)
    const TAX_LAYERS = [
        ['min' => 0,           'max' => 60_000_000,    'rate' => 5,  'label' => 'sd Rp 60 juta'],
        ['min' => 60_000_000,  'max' => 250_000_000,   'rate' => 15, 'label' => 'Rp 60 jt – Rp 250 jt'],
        ['min' => 250_000_000, 'max' => 500_000_000,   'rate' => 25, 'label' => 'Rp 250 jt – Rp 500 jt'],
        ['min' => 500_000_000, 'max' => 5_000_000_000, 'rate' => 30, 'label' => 'Rp 500 jt – Rp 5 M'],
        ['min' => 5_000_000_000, 'max' => PHP_INT_MAX, 'rate' => 35, 'label' => 'di atas Rp 5 M'],
    ];

    const NORMA_RATE = 0.5; // 50% norma penghitungan penghasilan neto

    /**
     * Ambil total penghasilan bruto yang diakui (PSAK 72)
     * Hanya booking dengan work_status = 'done'
     * Tahun berdasarkan end_datetime booking
     */
    public function getBruto(int $userId, int $year): float
    {
        return (float) DB::table('booking_payments')
            ->join('bookings', 'bookings.id', '=', 'booking_payments.booking_id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.work_status', 'done')
            ->whereYear('bookings.end_datetime', $year)
            ->sum('booking_payments.amount');
    }

    /**
     * Hitung PKP & breakdown lapisan pajak
     */
    public function calculate(int $userId, int $year): array
    {
        $profile = TaxProfile::where('user_id', $userId)->first();

        if (!$profile) {
            throw new \Exception('Profil pajak belum diatur. Silakan lengkapi profil pajak terlebih dahulu.');
        }

        $bruto     = $this->getBruto($userId, $year);
        $dpp       = $bruto * self::NORMA_RATE;
        $ptkpValue = $profile->ptkp_value;
        $pkp       = max(0, floor(($dpp - $ptkpValue) / 1000) * 1000); // dibulatkan ke bawah per 1000

        // Hitung pajak berlapis
        $layers             = $this->calculateLayers($pkp);
        $taxBeforeCorrection = array_sum(array_column($layers, 'tax'));

        // Koreksi +20% jika tidak punya NPWP
        $npwpCorrection = $profile->has_npwp ? 0 : ($taxBeforeCorrection * 0.20);
        $taxFinal       = $taxBeforeCorrection + $npwpCorrection;

        // Tarif efektif
        $effectiveRate = $bruto > 0 ? round(($taxFinal / $bruto) * 100, 2) : 0;

        return [
            'tax_year'               => $year,
            'bruto'                  => $bruto,
            'dpp'                    => $dpp,
            'ptkp_status'            => $profile->ptkp_status,
            'ptkp_value'             => $ptkpValue,
            'pkp'                    => $pkp,
            'tax_layers'             => $layers,
            'tax_before_correction'  => $taxBeforeCorrection,
            'npwp_correction'        => $npwpCorrection,
            'tax_final'              => $taxFinal,
            'effective_rate'         => $effectiveRate,
            'has_npwp'               => $profile->has_npwp,
        ];
    }

    /**
     * Hitung lapisan pajak Pasal 17 secara progresif
     */
    public function calculateLayers(float $pkp): array
    {
        $remaining = $pkp;
        $layers    = [];

        foreach (self::TAX_LAYERS as $layer) {
            if ($remaining <= 0) break;

            $taxableInLayer = min($remaining, $layer['max'] - $layer['min']);
            $tax            = $taxableInLayer * ($layer['rate'] / 100);

            $layers[] = [
                'label'      => $layer['label'],
                'min'        => $layer['min'],
                'max'        => $layer['max'],
                'rate'       => $layer['rate'],
                'pkp_layer'  => $taxableInLayer,
                'tax'        => $tax,
            ];

            $remaining -= $taxableInLayer;
        }

        return $layers;
    }

    /**
     * Simpan atau update hasil perhitungan ke tax_records
     */
    public function saveRecord(int $userId, int $year): TaxRecord
    {
        $data = $this->calculate($userId, $year);

        $record = TaxRecord::updateOrCreate(
            ['user_id' => $userId, 'tax_year' => $year],
            [
                'bruto'                  => $data['bruto'],
                'dpp'                    => $data['dpp'],
                'ptkp_status'            => $data['ptkp_status'],
                'ptkp_value'             => $data['ptkp_value'],
                'pkp'                    => $data['pkp'],
                'tax_layers'             => $data['tax_layers'],
                'tax_before_correction'  => $data['tax_before_correction'],
                'npwp_correction'        => $data['npwp_correction'],
                'tax_final'              => $data['tax_final'],
                'effective_rate'         => $data['effective_rate'],
                'calculated_at'          => now(),
                // credit_tax & tax_payable tidak di-reset agar tidak menghapus input user
            ]
        );

        // Hitung ulang tax_payable setelah simpan
        $record->tax_payable = $record->tax_final - $record->credit_tax;
        $record->save();

        return $record;
    }

    /**
     * Update kredit pajak (bukti potong 1721-VI) dan hitung ulang tax_payable
     */
    public function updateCredit(int $userId, int $year, float $creditTax): TaxRecord
    {
        $record = TaxRecord::where('user_id', $userId)
            ->where('tax_year', $year)
            ->firstOrFail();

        $record->credit_tax  = $creditTax;
        $record->tax_payable = $record->tax_final - $creditTax;
        $record->save();

        return $record;
    }
}