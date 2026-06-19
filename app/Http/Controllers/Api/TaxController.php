<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxProfile;
use App\Models\TaxRecord;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaxController extends Controller
{
    public function __construct(private TaxService $taxService) {}

    // ─── Profil Pajak ────────────────────────────────────────────────────────

    /**
     * GET /api/tax/profile
     * Ambil profil pajak user yang sedang login
     */
    public function getProfile(): JsonResponse
    {
        $profile = TaxProfile::firstOrNew(['user_id' => Auth::id()]);

        if (!$profile->exists) {
            $profile->ptkp_status = 'TK/0';
            $profile->has_npwp    = false;
        }

        return response()->json([
            'data' => [
                'has_npwp'    => $profile->has_npwp,
                'npwp'        => $profile->npwp,
                'ptkp_status' => $profile->ptkp_status,
                'ptkp_value'  => $profile->ptkp_value,
            ],
        ]);
    }

    /**
     * PUT /api/tax/profile
     * Simpan atau update profil pajak
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'has_npwp'    => 'required|boolean',
            'npwp'        => 'nullable|string|max:20',
            'ptkp_status' => ['required', Rule::in(array_keys(TaxProfile::PTKP_VALUES))],
        ]);

        // Validasi format NPWP jika punya
        if ($validated['has_npwp'] && !empty($validated['npwp'])) {
            if (!preg_match('/^\d{2}\.\d{3}\.\d{3}\.\d{1}-\d{3}\.\d{3}$/', $validated['npwp'])) {
                return response()->json([
                    'message' => 'Format NPWP tidak valid.',
                    'errors'  => ['npwp' => ['Format NPWP harus XX.XXX.XXX.X-XXX.XXX']],
                ], 422);
            }
        }

        $profile = TaxProfile::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'has_npwp'    => $validated['has_npwp'],
                'npwp'        => $validated['has_npwp'] ? $validated['npwp'] : null,
                'ptkp_status' => $validated['ptkp_status'],
            ]
        );

        return response()->json([
            'message' => 'Profil pajak berhasil disimpan.',
            'data'    => [
                'has_npwp'    => $profile->has_npwp,
                'npwp'        => $profile->npwp,
                'ptkp_status' => $profile->ptkp_status,
                'ptkp_value'  => $profile->ptkp_value,
            ],
        ]);
    }

    /**
     * GET /api/tax/ptkp-list
     * Daftar semua opsi PTKP
     */
    public function getPtkpList(): JsonResponse
    {
        $list = collect(TaxProfile::PTKP_VALUES)->map(function ($nilai, $kode) {
            return [
                'kode'      => $kode,
                'nilai'     => $nilai,
                'deskripsi' => TaxProfile::PTKP_DESCRIPTIONS[$kode],
            ];
        })->values();

        return response()->json(['data' => $list]);
    }

    // ─── Hitung & Simpan Pajak ───────────────────────────────────────────────

    /**
     * GET /api/tax/calculate/{year}
     * Hitung pajak tanpa menyimpan (preview)
     */
    public function calculate(int $year): JsonResponse
    {
        $this->validateYear($year);

        try {
            $data = $this->taxService->calculate(Auth::id(), $year);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/tax/save/{year}
     * Hitung dan simpan pajak ke database
     */
    public function save(int $year): JsonResponse
    {
        $this->validateYear($year);

        try {
            $record = $this->taxService->saveRecord(Auth::id(), $year);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Data pajak tahun {$year} berhasil disimpan.",
            'data'    => $this->formatRecord($record),
        ]);
    }

    // ─── Kredit Pajak ────────────────────────────────────────────────────────

    /**
     * PUT /api/tax/credit/{year}
     * Update kredit pajak (bukti potong 1721-VI)
     */
    public function updateCredit(Request $request, int $year): JsonResponse
    {
        $this->validateYear($year);

        $validated = $request->validate([
            'credit_tax' => 'required|numeric|min:0',
        ]);

        try {
            $record = $this->taxService->updateCredit(Auth::id(), $year, $validated['credit_tax']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => "Data pajak tahun {$year} belum tersimpan. Simpan dulu di tab Hitung Pajak.",
            ], 404);
        }

        return response()->json([
            'message' => 'Kredit pajak berhasil diperbarui.',
            'data'    => $this->formatRecord($record),
        ]);
    }

    // ─── Histori & Summary ───────────────────────────────────────────────────

    /**
     * GET /api/tax/history
     * Daftar semua histori pajak tahunan user
     */
    public function history(Request $request): JsonResponse
    {
        $records = TaxRecord::where('user_id', Auth::id())
            ->orderByDesc('tax_year')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $records->map(fn($r) => $this->formatRecord($r)),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    /**
     * GET /api/tax/summary
     * Ringkasan akumulasi semua tahun
     */
    public function summary(): JsonResponse
    {
        $records = TaxRecord::where('user_id', Auth::id())->get();

        if ($records->isEmpty()) {
            return response()->json([
                'data' => [
                    'total_bruto'      => 0,
                    'total_tax_paid'   => 0,
                    'total_credit_tax' => 0,
                    'total_years'      => 0,
                    'first_tax_year'   => null,
                    'latest_tax_year'  => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'total_bruto'      => $records->sum('bruto'),
                'total_tax_paid'   => $records->sum('tax_final'),
                'total_credit_tax' => $records->sum('credit_tax'),
                'total_years'      => $records->count(),
                'first_tax_year'   => $records->min('tax_year'),
                'latest_tax_year'  => $records->max('tax_year'),
            ],
        ]);
    }

    /**
     * DELETE /api/tax/{year}
     * Hapus data pajak tahun tertentu
     */
    public function destroy(int $year): JsonResponse
    {
        $deleted = TaxRecord::where('user_id', Auth::id())
            ->where('tax_year', $year)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => "Data pajak tahun {$year} tidak ditemukan."], 404);
        }

        return response()->json(['message' => "Data pajak tahun {$year} berhasil dihapus."]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function validateYear(int $year): void
    {
        $currentYear = (int) date('Y');
        abort_if($year < 2020 || $year > $currentYear, 422, 'Tahun pajak tidak valid.');
    }

    private function formatRecord(TaxRecord $r): array
    {
        return [
            'id'                     => $r->id,
            'tax_year'               => $r->tax_year,
            'bruto'                  => (float) $r->bruto,
            'dpp'                    => (float) $r->dpp,
            'ptkp_status'            => $r->ptkp_status,
            'ptkp_value'             => (float) $r->ptkp_value,
            'pkp'                    => (float) $r->pkp,
            'tax_layers'             => $r->tax_layers,
            'tax_before_correction'  => (float) $r->tax_before_correction,
            'npwp_correction'        => (float) $r->npwp_correction,
            'tax_final'              => (float) $r->tax_final,
            'credit_tax'             => (float) $r->credit_tax,
            'tax_payable'            => (float) $r->tax_payable,
            'effective_rate'         => (float) $r->effective_rate,
            'calculated_at'          => $r->calculated_at?->toDateTimeString(),
        ];
    }
}