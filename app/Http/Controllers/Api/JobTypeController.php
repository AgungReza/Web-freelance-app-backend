<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\JobType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobTypeController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $jobTypes=JobType::where('user_id',$request->user()->id)
            ->latest()
            ->get();

        return ApiResponse::success(
            'Job type berhasil diambil',
            $jobTypes
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $validator=Validator::make($request->all(),[
            'job_name'=>'required|string|max:255',
        ]);

        if($validator->fails()){
            return ApiResponse::error(
                'Validation failed',
                $validator->errors(),
                422
            );
        }

        $jobType=JobType::create([
            'user_id'=>$request->user()->id,
            'job_name'=>$request->job_name,
        ]);

        return ApiResponse::success(
            'Job type berhasil dibuat',
            $jobType,
            201
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(Request $request,$id)
    {
        $jobType=JobType::where(
                'user_id',
                $request->user()->id
            )
            ->where(
                'id',
                $id
            )
            ->first();

        if(!$jobType){

            return ApiResponse::error(
                'Job type tidak ditemukan',
                null,
                404
            );

        }

        return ApiResponse::success(
            'Detail job type',
            $jobType
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET PACKAGES BY JOB TYPE
    |--------------------------------------------------------------------------
    */

    public function packages(
        Request $request,
        $id
    ){

        $jobType=JobType::where(
                'user_id',
                $request->user()->id
            )

            ->where(
                'id',
                $id
            )

            ->with([

                'jobPackages:id,job_type_id,package_name,price,discount,description'

            ])

            ->first();


        if(!$jobType){

            return ApiResponse::error(

                'Job type tidak ditemukan',

                null,

                404

            );

        }


        return ApiResponse::success(

            'Job packages berhasil diambil',

            $jobType->jobPackages

        );

    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        $id
    ){

        $validator=Validator::make(
            $request->all(),
            [
                'job_name'=>'required|string|max:255',
            ]
        );


        if($validator->fails()){

            return ApiResponse::error(

                'Validation failed',

                $validator->errors(),

                422

            );

        }


        $jobType=JobType::where(
                'user_id',
                $request->user()->id
            )

            ->where(
                'id',
                $id
            )

            ->first();


        if(!$jobType){

            return ApiResponse::error(

                'Job type tidak ditemukan',

                null,

                404

            );

        }


        $jobType->update([

            'job_name'=>$request->job_name

        ]);


        return ApiResponse::success(

            'Job type berhasil diupdate',

            $jobType

        );

    }



    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        $id
    ){

        $jobType=JobType::where(
                'user_id',
                $request->user()->id
            )

            ->where(
                'id',
                $id
            )

            ->first();


        if(!$jobType){

            return ApiResponse::error(

                'Job type tidak ditemukan',

                null,

                404

            );

        }


        $jobType->delete();


        return ApiResponse::success(

            'Job type berhasil dihapus'

        );

    }

}