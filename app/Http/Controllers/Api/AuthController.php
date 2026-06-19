<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\User;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class AuthController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | Generate User Code
    |--------------------------------------------------------------------------
    */

    private function generateUserCode(): string
    {

        do {

            $code =

                'USR_' .

                strtoupper(

                    Str::random(10)

                );

        }

        while (

            User::where(

                'user_code',

                $code

            )

            ->exists()

        );


        return $code;

    }




    /*
    |--------------------------------------------------------------------------
    | Register
    |--------------------------------------------------------------------------
    */

    public function register(
        Request $request
    )
    {

        $validator =

            Validator::make(

                $request->all(),

                [

                    'fullname' =>

                        'required|string|min:3|max:255',



                    'email' =>

                        'required|email|unique:users,email',



                    'password' => [

                        'required',

                        Password::min(8)

                            ->mixedCase()

                            ->numbers()

                            ->symbols()

                    ]

                ]

            );



        if (

            $validator->fails()

        ) {

            return ApiResponse::error(

                'Validasi gagal',

                $validator->errors(),

                422

            );

        }




        DB::beginTransaction();


        try {


            $user =

                User::create([

                    'fullname' =>

                        trim(

                            $request->fullname

                        ),



                    'email' =>

                        strtolower(

                            trim(

                                $request->email

                            )

                        ),



                    // auto hash via User model

                    'password' =>

                        $request->password,



                    'user_code' =>

                        $this

                            ->generateUserCode()

                ]);




            DB::commit();




            return ApiResponse::success(

                'Register berhasil',

                [

                    'id' =>

                        $user->id,


                    'fullname' =>

                        $user->fullname,


                    'email' =>

                        $user->email,


                    'user_code' =>

                        $user->user_code

                ],

                201

            );

        }



        catch (

            QueryException $e

        ) {


            DB::rollBack();


            Log::error(

                'Register DB Error',

                [

                    'message' =>

                        $e->getMessage(),


                    'ip' =>

                        $request->ip()

                ]

            );




            return ApiResponse::error(

                'Data gagal disimpan',

                null,

                500

            );

        }




        catch (

            Exception $e

        ) {


            DB::rollBack();



            Log::error(

                'Register Error',

                [

                    'message' =>

                        $e->getMessage(),


                    'ip' =>

                        $request->ip()

                ]

            );



            return ApiResponse::error(

                'Terjadi kesalahan server',

                null,

                500

            );

        }

    }




    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    */

    public function login(
        Request $request
    )
    {

        $validator =

            Validator::make(

                $request->all(),

                [

                    'email' =>

                        'required|email',



                    'password' =>

                        'required|string'

                ]

            );




        if (

            $validator->fails()

        ) {

            return ApiResponse::error(

                'Validasi gagal',

                $validator->errors(),

                422

            );

        }




        try {


            $user =

                User::where(

                    'email',

                    strtolower(

                        trim(

                            $request->email

                        )

                    )

                )

                ->first();




            if (

                !$user ||

                !Hash::check(

                    $request->password,

                    $user->password

                )

            ) {

                return ApiResponse::error(

                    'Email atau password salah',

                    null,

                    401

                );

            }




            /*
            |--------------------------------------------------------------------------
            | Single Active Session
            |--------------------------------------------------------------------------
            */

            $user

                ->tokens()

                ->delete();




            $token =

                $user

                ->createToken(

                    'api_token'

                )

                ->plainTextToken;




            return ApiResponse::success(

                'Login berhasil',

                [

                    'user' => [

                        'id' =>

                            $user->id,


                        'fullname' =>

                            $user->fullname,


                        'email' =>

                            $user->email,


                        'user_code' =>

                            $user->user_code

                    ],



                    'token' =>

                        $token

                ]

            );

        }




        catch (

            Exception $e

        ) {


            Log::error(

                'Login Error',

                [

                    'message' =>

                        $e->getMessage(),


                    'ip' =>

                        $request->ip()

                ]

            );




            return ApiResponse::error(

                'Terjadi kesalahan server',

                null,

                500

            );

        }

    }




    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    public function me(
        Request $request
    )
    {

        $user =

            $request
                ->user();




        return ApiResponse::success(

            'Profile berhasil diambil',

            [

                'id' =>

                    $user->id,


                'fullname' =>

                    $user->fullname,


                'email' =>

                    $user->email,


                'user_code' =>

                    $user->user_code,


                'created_at' =>

                    $user->created_at

            ]

        );

    }



    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function logout(
        Request $request
    )
    {

        try {


            $request

                ->user()

                ?->currentAccessToken()

                ?->delete();




            return ApiResponse::success(

                'Logout berhasil'

            );

        }




        catch (

            Exception $e

        ) {


            Log::error(

                'Logout Error',

                [

                    'message' =>

                        $e->getMessage(),


                    'ip' =>

                        $request->ip()

                ]

            );




            return ApiResponse::error(

                'Logout gagal',

                null,

                500

            );

        }

    }

}