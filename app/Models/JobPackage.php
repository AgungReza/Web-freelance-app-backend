<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JobPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_type_id',
        'package_name',
        'price',
        'discount',
        'description',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function jobType()
    {
        return $this->belongsTo(JobType::class);
    }
}
