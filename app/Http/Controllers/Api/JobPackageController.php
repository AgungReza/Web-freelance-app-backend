<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\JobPackage;
use App\Models\JobType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobPackageController extends Controller
{
    /* ================= LIST ================= */

    public function index(Request $request)
    {
        $query = JobPackage::with('jobType')
            ->whereHas('jobType', fn($q) =>
                $q->where('user_id', $request->user()->id)
            );

        if ($request->filled('job_type_id')) {
            $query->where('job_type_id', $request->job_type_id);
        }

        return ApiResponse::success(
            'Data package berhasil diambil',
            $query->latest()->get()
        );
    }

    /* ================= CREATE ================= */

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'job_type_id' => 'required|exists:job_types,id',
            'package_name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validation failed',
                $validator->errors(),
                422
            );
        }

        $jobType = JobType::where('user_id', $request->user()->id)
            ->find($request->job_type_id);

        if (!$jobType) {
            return ApiResponse::error(
                'Job type tidak ditemukan',
                null,
                404
            );
        }

        $package = JobPackage::create([
            'job_type_id' => $request->job_type_id,
            'package_name' => $request->package_name,
            'price' => $request->price,
            'description' => $request->description
        ]);

        return ApiResponse::success(
            'Package berhasil dibuat',
            $package,
            201
        );
    }

    /* ================= DETAIL ================= */

    public function show(Request $request, $id)
    {
        $package = JobPackage::with('jobType')
            ->whereHas('jobType', fn($q) =>
                $q->where('user_id', $request->user()->id)
            )
            ->find($id);

        if (!$package) {
            return ApiResponse::error(
                'Package tidak ditemukan',
                null,
                404
            );
        }

        return ApiResponse::success(
            'Detail package',
            $package
        );
    }

    /* ================= UPDATE ================= */

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'package_name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validation failed',
                $validator->errors(),
                422
            );
        }

        $package = JobPackage::where('id', $id)
            ->whereHas('jobType', fn($q) =>
                $q->where('user_id', $request->user()->id)
            )
            ->first();

        if (!$package) {
            return ApiResponse::error(
                'Package tidak ditemukan',
                null,
                404
            );
        }

        $package->update([
            'package_name' => $request->package_name,
            'price' => $request->price,
            'description' => $request->description
        ]);

        return ApiResponse::success(
            'Package berhasil diupdate',
            $package
        );
    }

    /* ================= DELETE ================= */

    public function destroy(Request $request, $id)
    {
        $package = JobPackage::where('id', $id)
            ->whereHas('jobType', fn($q) =>
                $q->where('user_id', $request->user()->id)
            )
            ->first();

        if (!$package) {
            return ApiResponse::error(
                'Package tidak ditemukan',
                null,
                404
            );
        }

        $package->delete();

        return ApiResponse::success(
            'Package berhasil dihapus'
        );
    }
}