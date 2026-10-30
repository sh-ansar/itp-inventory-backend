<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetCategory extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'code', 'name'];
}